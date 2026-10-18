<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AiProviderException;
use App\Services\Ai\CategorySearchService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Output\OutputInterface;

class SearchAiCategory extends Command
{
    protected $signature = 'ai:search {category : Category slug or ID}
        {--prompt= : Research instructions}
        {--location= : Optional location}
        {--budget-min= : Optional minimum amount}
        {--budget-max= : Optional maximum amount}
        {--currency= : Optional three-letter currency code}
        {--keyword=* : Repeat for multiple keywords}
        {--max-items=20 : Result limit (1-100)}
        {--model= : Optional provider model ID}';

    protected $description = 'Search a category through the selected AI provider; return JSON without persisting results';

    public function handle(CategorySearchService $search): int
    {
        $filters = [];
        if ($this->option('location') !== null) {
            $filters['location'] = $this->option('location');
        }
        foreach (['min' => 'budget-min', 'max' => 'budget-max', 'currency' => 'currency'] as $field => $option) {
            if ($this->option($option) !== null) {
                $filters['budget'][$field] = $this->option($option);
            }
        }
        if ($this->option('keyword') !== []) {
            $filters['keywords'] = $this->option('keyword');
        }
        $options = ['max_items' => $this->option('max-items')];
        if ($this->option('model') !== null) {
            $options['model'] = $this->option('model');
        }
        try {
            $result = $search->search($this->argument('category'), $this->option('prompt') ?? '', $filters, $options);
            $this->output->write(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL, false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error(json_encode($exception->errors(), JSON_UNESCAPED_UNICODE));

            return self::INVALID;
        } catch (AiProviderException $exception) {
            $this->error($exception->errorCode.': '.$exception->getMessage());
            if ($exception->requestId !== null) {
                $this->error('request_id='.$exception->requestId.'; phase='.($exception->phase ?? 'configuration'));
            }

            return self::FAILURE;
        }
    }
}
