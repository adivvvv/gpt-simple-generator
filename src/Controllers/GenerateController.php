<?php
// src/Controllers/GenerateController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Http;
use App\Support\Logger;
use App\Support\Validator;
use App\Support\Env;
use App\Services\OpenAIService;
use App\Services\PubMedService;
use App\Services\ArticleGenerator;

final class GenerateController
{
    public function handle(): void
    {
        $body = Http::body();
        $lang        = (string)($body['lang'] ?? '');
        $keywords    = array_values(array_filter((array)($body['keywords'] ?? [])));
        $paragraphs  = (int)($body['paragraphs'] ?? 9);
        $faqCount    = (int)($body['faqCount'] ?? 8);
        $styleFlags  = array_values(array_filter((array)($body['styleFlags'] ?? ['human-like','evidence-based'])));
        $special     = (string)($body['specialRequirements'] ?? '');

        if (!Validator::lang($lang) || empty($keywords)) {
            Http::json(['error' => 'Invalid lang or keywords'], 422);
        }

        $pubmed = new PubMedService();
        $refs   = $pubmed->context($lang, $keywords, (int)(Env::get('PUBMED_RETMAX', '12')));

        $openai = new OpenAIService();
        $gen    = new ArticleGenerator($openai);

        $article = $gen->generate([
            'lang' => $lang,
            'keywords' => $keywords,
            'paragraphs' => $paragraphs,
            'faqCount' => $faqCount,
            'styleFlags' => $styleFlags,
            'specialRequirements' => $special,
        ], $refs);

        Logger::info('Generated article', ['lang'=>$lang,'kw'=>$keywords,'pmids'=>array_column($refs,'pmid')]);
        Http::json($article, 200);
    }
}