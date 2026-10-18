<?php

namespace App\Integrations\Contracts;

use App\Models\Integrations\DataSource;
use App\Models\Integrations\DataSourceConfiguration;

interface DataSourceAdapterInterface
{
    public function metadata(DataSource $source): array;

    public function headers(DataSource $source, int $sheetId, int $headerRow): array;

    public function rows(DataSource $source, DataSourceConfiguration $configuration): array;
}
