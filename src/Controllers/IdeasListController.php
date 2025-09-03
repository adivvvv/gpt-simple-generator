<?php
// src/Controllers/IdeasListController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Http;
use App\Services\IdeaStore;

final class IdeasListController
{
    public function handle(): void
    {
        $lang  = (string)($_GET['lang'] ?? 'en');
        $limit = (int)($_GET['limit'] ?? 100);

        $store = new IdeaStore($lang);
        $ideas = $store->list($limit, true);

        Http::json(['lang'=>$lang,'count'=>count($ideas),'total'=>$store->count(),'ideas'=>$ideas], 200);
    }
}
