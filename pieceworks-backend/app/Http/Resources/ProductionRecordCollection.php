<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class ProductionRecordCollection extends ResourceCollection
{
    public $collects = ProductionRecordResource::class;
}
