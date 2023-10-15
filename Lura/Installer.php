<?php
// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

use Illuminate\Contracts\Filesystem\Filesystem;
use NormanHuth\ConsoleApp\LuraCommand;
use NormanHuth\ConsoleApp\LuraInstaller;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Illuminate\Support\Str;

class Installer extends LuraInstaller
{
    protected Filesystem $storage;
    protected string $appName;
    protected bool $dev;
    protected ?string $starterKit;
    protected bool $jetstreamTeams = true;
    protected bool $installNova = false;
    protected bool $installInertia = false;
    protected bool $SSR = true;
    protected bool $docker = false;
    protected string $appFolder = '';
    protected string $laravelNovaKey = '';
    protected int $laravelMainVersion = 0;

    protected array $versions = [];

    /**
     * The Command Instance
     *
     * @var mixed
     */
    protected mixed $command;

    /**
     * Execute the installer console command.
     *
     * @param mixed|LuraCommand $command
     * @return int
     */
    public function runLura(mixed $command): int
    {
        $this->command = $command;
        $this->setStorageDisk();
        $this->appName = $this->getAppName();
        $this->appFolder = $this->command->getRepoSlug($this->appName);

        if (!$this->command->existCheck($this->appFolder)) {
            $this->command->error('The directory ' . $this->appFolder . ' already exist');

            return SymfonyCommand::FAILURE;
        }

        if (
            $contents = file_get_contents('https://raw.githubusercontent.com/Muetze42/data/main/storage/versions.json')
        ) {
            if (Str::isJson($contents)) {
                $this->versions = json_decode($contents, true);
            }
        }

        $this->questions();
        $this->install();
        $this->createEnv();
        $this->changeComposerJson();
        $this->changePackageJson();
        $this->customChanges();
        $this->command->moveExistBack($this->appFolder);
        $this->composerInstall();
        $this->afterComposerInstall();
        $this->updateAppServiceProvider();

        $this->command->info(sprintf(
            'Your Laravel app „%s“ is now available in „%s“',
            $this->appName,
            $this->command->cwdDisk->get($this->appFolder)
        ));

        return SymfonyCommand::SUCCESS;
    }

    protected function updateAppServiceProvider(): void
    {
        $target = $this->appFolder . '/app/Providers/AppServiceProvider.php';
        $content = $this->command->cwdDisk->get($target);

        $content = str_replace(
            'use Illuminate\Support\ServiceProvider;',
            'use Illuminate\Support\ServiceProvider;' . "\n#use Illuminate\Http\Resources\Json\JsonResource;" .
            "\nuse Illuminate\Validation\Rules\Password;",
            $content
        );
        $content = $this->command->replaceNth('/\/\//', '#JsonResource::withoutWrapping();

        Password::defaults(static function () {
            return Password::min(12)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised();
        });', $content);

        $this->command->cwdDisk->put($target, $content);
    }

    protected function publishFolder(string $from, string $to): void
    {
        $this->command->filesystem->copyDirectory(
            $this->storage->path($from),
            $this->command->cwdDisk->path($this->appFolder . '/' . $to)
        );
    }

    protected function afterComposerInstall(): void
    {
        /* Change stack logging channel driver to daily */
        $content = $this->command->cwdDisk->get($this->appFolder . '/config/logging.php');
        $content = str_replace("'channels' => ['single']", "'channels' => ['daily']", $content);
        $this->command->cwdDisk->put($this->appFolder . '/config/logging.php', $content);

        $this->runCommand('php artisan key:generate --ansi');
        if ($this->installNova) {
            $this->publishFolder('nova', '');
            $this->runCommand('php artisan nova:install');
        }

        switch ($this->starterKit) {
            case 'Breeze':
                $this->runCommand('php artisan breeze:install');
                break;
            case 'Breeze with Vue scaffolding':
                $ssr = $this->SSR ? ' --ssr' : '';
                $this->runCommand('php artisan breeze:install vue' . $ssr);
                break;
            case 'Breeze with React scaffolding':
                $ssr = $this->SSR ? ' --ssr' : '';
                $this->runCommand('php artisan breeze:install react' . $ssr);
                break;
            case 'Breeze with Next.js / API scaffolding':
                $this->runCommand('php artisan breeze:install api');
                break;
            case 'Jetstream with Livewire':
                $teams = $this->jetstreamTeams ? ' --teams' : '';
                $this->runCommand('php artisan jetstream:install livewire' . $teams);
                break;
            case 'Jetstream with Inertia':
                $ssr = $this->SSR ? ' --ssr' : '';
                $teams = $this->jetstreamTeams ? ' --teams' : '';
                $this->runCommand('php artisan jetstream:install inertia' . $teams . $ssr);
                break;
        }

        if ($this->installInertia) {
            $this->runCommand('php artisan inertia:middleware');

            $search = '\\Illuminate\\Routing\\Middleware\\SubstituteBindings::class,';
            $replace = '\\Illuminate\\Routing\\Middleware\\SubstituteBindings::class,' . "\n" .
                '            \\App\\Http\\Middleware\\HandleInertiaRequests::class,';
            $subject = $this->command->cwdDisk->get($this->appFolder . '/app/Http/Kernel.php');
            $content = preg_replace(
                '/' . preg_quote($search, '/') . '/',
                $replace,
                $subject,
                1
            );

            $this->command->cwdDisk->put($this->appFolder . '/app/Http/Kernel.php', $content);
        }
    }

    protected function runCommand(string $command): void
    {
        $command = 'cd ' . $this->command->cwdDisk->path($this->appFolder) . ' && ' . $command;

        $process = Process::fromShellCommandline($command);
        $process->run(function ($type, $line) {
            $this->command->line('    ' . $line);
        });
    }

    /**
     * @return void
     */
    protected function composerInstall(): void
    {
        $this->runCommand($this->command->composer . ' install --prefer-dist');
    }

    protected function formatVersion(string $package, string $default, string $type = 'npm')
    {
        $version = data_get($this->versions, $type . '.' . $package, $default);

        return str_starts_with($version, '^') ? $version : '^' . $version;
    }

    protected function customChanges(): void
    {
        //
    }

    /**
     * @return void
     */
    protected function changePackageJson(): void
    {
        $packageJson = json_decode($this->command->cwdDisk->get($this->appFolder . '/package.json'), true);
        $devDependencies = data_get($packageJson, 'devDependencies', []);

        if ($this->installInertia) {
            $devDependencies = static::addPackage(
                $devDependencies,
                'vue-loader',
                $this->formatVersion('vue-loader', '17.3.0')
            );
            $devDependencies = static::addPackage(
                $devDependencies,
                '@babel/plugin-syntax-dynamic-import',
                $this->formatVersion('@babel/plugin-syntax-dynamic-import', '7.8.3')
            );
            $devDependencies = static::addPackage(
                $devDependencies,
                '@inertiajs/vue3',
                $this->formatVersion('@inertiajs/vue3', '1.0.12')
            );
            $devDependencies = static::addPackage(
                $devDependencies,
                'vue',
                $this->formatVersion('vue', '3.3.4')
            );
        }

        data_set($packageJson, 'devDependencies', $devDependencies);
        $this->command->cwdDisk->put(
            $this->appFolder . '/package.json',
            json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * @return void
     */
    protected function changeComposerJson(): void
    {
        $composerJson = json_decode($this->command->cwdDisk->get($this->appFolder . '/composer.json'), true);
        $requirements = data_get($composerJson, 'require', []);
        $devRequirements = data_get($composerJson, 'require-dev', []);
        $php = $composerJson['require']['php'];
        unset($composerJson['require']['php']);

        $version = data_get($requirements, 'laravel/framework');
        $version = explode('.', $version)[0];
        $this->laravelMainVersion = preg_replace('/\D/', '', $version);

        if ($this->installNova) {
            $composerJson = static::keyValueInsertToPosition(
                $composerJson,
                'repositories',
                [[
                    'type' => 'composer',
                    'url' => 'https://nova.laravel.com'
                ]],
                4
            );

            $requirements = static::addPackage($requirements, 'laravel/nova', '^v4.27.14');
        }

        if (
            in_array($this->starterKit, [
            'Breeze',
            'Breeze with Vue scaffolding',
            'Breeze with React scaffolding',
            'Breeze with Next.js / API scaffolding',
            ])
        ) {
            $devRequirements = static::addPackage($devRequirements, 'laravel/breeze', '^v1.25.0');
        }

        if (
            in_array($this->starterKit, [
            'Jetstream with Livewire',
            'Jetstream with Inertia',
            ])
        ) {
            $devRequirements = static::addPackage($devRequirements, 'laravel/jetstream', '^v4.0.3');
        }

        if ($this->installInertia) {
            $requirements = static::addPackage($requirements, 'inertiajs/inertia-laravel', '^v0.6.10');
        }

        data_set($composerJson, 'require', array_merge(['php' => $php], $requirements));
        data_set($composerJson, 'require-dev', $devRequirements);
        $this->command->cwdDisk->put(
            $this->appFolder . '/composer.json',
            json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * @return void
     */
    protected function createEnv(): void
    {
        $content = '';
        $lines = explode("\n", trim($this->command->cwdDisk->get($this->appFolder . '/.env.example')));
        foreach ($lines as $line) {
            $line = trim($line);
            $key = explode('=', $line)[0];

            switch ($key) {
                case 'APP_NAME':
                    $content .= 'APP_NAME="' . $this->appName . '"';
                    break;
                case 'APP_URL':
                    /** @noinspection HttpUrlsUsage */
                    $content .= 'APP_URL=http://' . Str::slug($this->appName) . '.test';
                    break;
                case 'DB_HOST':
                    $value = $this->docker ? 'mysql' : '127.0.0.1';
                    $content .= 'DB_HOST=' . $value;
                    break;
                case 'DB_DATABASE':
                    $content .= 'DB_DATABASE=' . Str::slug($this->appName, '_');
                    break;
                case 'DB_USERNAME':
                    $value = $this->docker ? 'sail' : 'root';
                    $content .= 'DB_USERNAME=' . $value;
                    break;
                case 'DB_PASSWORD':
                    $value = $this->docker ? 'password' : '';
                    $content .= 'DB_PASSWORD=' . $value;
                    break;
                case 'MEMCACHED_HOST':
                    $value = $this->docker ? 'memcached' : '127.0.0.1';
                    $content .= 'MEMCACHED_HOST=' . $value;
                    break;
                case 'REDIS_HOST':
                    $value = $this->docker ? 'redis' : '127.0.0.1';
                    $content .= 'REDIS_HOST=' . $value;
                    break;
                default:
                    $content .= $line;
            }
            $content .= "\n";
        }

        if ($this->docker) {
            $content .= "\n\nSCOUT_DRIVER=meilisearch\nMEILISEARCH_HOST=http://meilisearch:7700";
        }

        if ($this->installNova) {
            $content = str_replace('LOG_CHANNEL', "NOVA_LICENSE_KEY=\n\nLOG_CHANNEL", $content);
        }

        $content = trim($content);
        $this->command->cwdDisk->put($this->appFolder . '/.env.example', $content);
        if ($this->installNova) {
            $content = str_replace('NOVA_LICENSE_KEY=', 'NOVA_LICENSE_KEY=' . $this->getNovaKey(), $content);
        }

        $this->command->cwdDisk->put($this->appFolder . '/.env', $content);
    }

    /**
     * @return string
     */
    protected function getNovaKey(): string
    {
        $command = $this->command->composer . ' config --global http-basic.nova.laravel.com.password';
        $process = Process::fromShellCommandline($command);
        $process->run(function ($type, $line) {
            if ($type == 'out') {
                $this->laravelNovaKey = trim($line);
            }
        });

        return $this->laravelNovaKey;
    }

    /**
     * @return void
     */
    protected function install(): void
    {
        $branch = $this->dev ? 'dev-master ' : '';
        $command = $this->command->composer . ' create-project laravel/laravel ' . $this->appFolder . ' ' . $branch .
            '--no-install --no-interaction --no-scripts --remove-vcs --prefer-dist';
        $process = Process::fromShellCommandline($command);
        $process->start();
        foreach ($process as $data) {
            $this->command->line($data);
        }

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * @return void
     */
    protected function questions(): void
    {
        $this->questionDev();
        $this->questionStarterKit();
        $this->questionDocker();
    }

    protected function questionDev(): void
    {
        $this->dev = $this->command->confirm(
            'Do You want install the DEVELOPMENT version of Laravel instead latest release?',
            false
        );
    }

    protected function questionStarterKit(): void
    {
        $this->starterKit = $this->command->choice(
            'Install starter kit?',
            [
                'no',
                'Breeze',
                'Breeze with Vue scaffolding',
                'Breeze with React scaffolding',
                'Breeze with Next.js / API scaffolding',
                'Jetstream with Livewire',
                'Jetstream with Inertia',
            ],
            'no'
        );

        $this->command->info('Starter Kit: ' . $this->starterKit);

        if (in_array($this->starterKit, ['Jetstream with Livewire', 'Jetstream with Inertia'])) {
            $this->jetstreamTeams = $this->command->confirm('Enable team support?');
            $word = $this->jetstreamTeams ? 'with' : 'without';
            $this->command->info('Install Jetstream ' . $word . ' team support');
        }

        if (
            in_array(
                $this->starterKit,
                ['Jetstream with Inertia', 'Breeze with Vue scaffolding', 'Breeze with React scaffolding']
            )
        ) {
            $this->SSR = $this->command->confirm('Install stack with SSR support?', false);
        }

        if (data_get($this->command->installerConfig, 'laravel-nova', true)) {
            $this->installNova = $this->command->confirm('Install Laravel Nova?', false);
        }

        if ($this->starterKit == 'no' && data_get($this->command->installerConfig, 'inertia', true)) {
            $this->installInertia = $this->command->confirm('Install Inertia?', false);
        }
    }

    protected function questionDocker(): void
    {
        if (data_get($this->command->installerConfig, 'docker', true)) {
            $this->docker = $this->command->confirm('Add Docker files?', false);
            $word = $this->docker ? 'Add' : 'Don’t add';
            $this->command->info($word . ' Docker files');
        }
    }

    /**
     * @return string
     */
    protected function getAppName(): string
    {
        $appName = $this->command->ask('Please enter the app name');

        return !empty($appName) ? $appName : $this->getAppName();
    }

    /**
     * @return void
     */
    protected function setStorageDisk(): void
    {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
        $this->storage = $this->command->createFilesystem($dir);
    }

    /**
     * @param array $key
     * @param string $package
     * @param string $version
     * @return array
     */
    protected static function addPackage(array $key, string $package, string $version): array
    {
        $key = array_merge($key, [$package => $version]);
        ksort($key, SORT_NATURAL | SORT_FLAG_CASE);

        return $key;
    }


    /**
     * Add an array key value pair to specific position into an existing key value array.
     *
     * @see  https://github.com/Muetze42/helpers-collection-php
     * @todo globalize
     *
     * @param array  $array
     * @param string $key
     * @param mixed  $value
     * @param int    $position
     * @param bool   $insertAfter
     *
     * @return array
     */
    public static function keyValueInsertToPosition(
        array $array,
        string $key,
        mixed $value,
        int $position,
        bool $insertAfter = true
    ): array {
        $results = [];
        $items = array_keys($array);

        foreach ($items as $index => $item) {
            if ($index == $position && !$insertAfter) {
                $results[$key] = $value;
            }

            $results[$item] = $array[$item];

            if ($index == $position && $insertAfter) {
                $results[$key] = $value;
            }
        }

        return $results;
    }
}
