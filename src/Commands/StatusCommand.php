<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Commands;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use Batteryincluded\BatteryincludedBundle\Profiler\ApiDataCollector;
use BatteryIncludedSdk\Client\ApiClient;
use Composer\InstalledVersions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'bi:status', description: 'Shows the configuration and checks the connection to the collections.')]
class StatusCommand extends Command
{
    public function __construct(private readonly CollectionRegistry $collections)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'Checks only this collection (name in the bundle configuration)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $names = $input->getOption('collection') !== null ? [(string) $input->getOption('collection')] : $this->collections->getNames();

        $io->title('BatteryIncluded');
        $io->definitionList(
            ['batteryincluded/batteryincluded-php-sdk' => $this->version('batteryincluded/batteryincluded-php-sdk')],
            ['batteryincluded/batteryincluded-bundle' => $this->version('batteryincluded/batteryincluded-bundle')],
        );

        $failed = 0;
        foreach ($names as $name) {
            try {
                $config = $this->collections->getConfig($name);
            } catch (\InvalidArgumentException $exception) {
                $io->error($exception->getMessage());

                return Command::INVALID;
            }

            $io->section($name === BatteryincludedBundle::DEFAULT_COLLECTION ? 'Collection' : 'Collection ' . $name);
            $rows = [
                ['Base URL' => $config['baseUrl']],
                ['Collection' => $config['collection']],
                ['API key' => ApiDataCollector::maskApiKey($config['apiKey'])],
            ];

            // e.g. right after the installation, the recipe adds the variables empty to .env
            $missing = array_keys(array_filter(['collection' => $config['collection'], 'api key' => $config['apiKey']], static fn (string $value) => mb_trim($value) === ''));
            if ($missing !== []) {
                $rows[] = ['Status' => sprintf('<error>%s not configured</>, set BATTERYINCLUDED_COLLECTION and BATTERYINCLUDED_API_KEY in .env.local', implode(' and ', $missing))];
                $io->definitionList(...$rows);
                ++$failed;
                continue;
            }

            $start = microtime(true);
            try {
                $response = $this->collections->get($name, ApiClient::class)->getJson('/documents/browse?' . http_build_query(['q' => '', 'per_page' => 1]), []);
                $duration = (int) round((microtime(true) - $start) * 1000);
                $body = $response->getBody();
                $ok = $response->getStatusCode() < 400;
                $rows[] = ['Status' => sprintf('<%s>%d</> in %d ms', $ok ? 'info' : 'error', $response->getStatusCode(), $duration)];
                if ($ok) {
                    $rows[] = ['Documents' => (string) ($body['found'] ?? $body['out_of'] ?? '?')];
                    $rows[] = ['Search time' => ($body['search_time_ms'] ?? '?') . ' ms'];
                } else {
                    $rows[] = ['Response' => mb_substr($response->getRawResponse(), 0, 500)];
                }
            } catch (\Throwable $exception) {
                $ok = false;
                $rows[] = ['Status' => '<error>' . $exception->getMessage() . '</>'];
            }

            $io->definitionList(...$rows);
            if (!$ok) {
                ++$failed;
            }
        }

        if ($failed > 0) {
            $io->error(sprintf('%d of %d collections are not reachable.', $failed, count($names)));

            return Command::FAILURE;
        }

        $io->success('All collections are reachable.');

        return Command::SUCCESS;
    }

    private function version(string $package): string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled($package)) {
            return 'n/a';
        }

        return mb_trim(InstalledVersions::getPrettyVersion($package) . ' ' . mb_substr((string) InstalledVersions::getReference($package), 0, 7));
    }
}
