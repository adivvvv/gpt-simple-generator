<?php
// src/Controllers/GenerateController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Http;
use App\Support\Validator;
use App\Support\Env;
use App\Support\Logger;
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

        $minSent     = (int)($body['minSentencesPerParagraph'] ?? (int)(Env::get('CONTENT_MIN_SENTENCES', '4')));
        $pmidPolicy  = (string)($body['pmidPolicy'] ?? 'auto'); // auto|none|limited

        if (!Validator::lang($lang) || empty($keywords)) {
            Http::json(['error' => 'Invalid lang or keywords'], 422);
        }

        $pubmed = new PubMedService();
        $refs   = $pubmed->context($lang, $keywords, (int)(Env::get('PUBMED_RETMAX', '12')));

        $gen = new ArticleGenerator(new OpenAIService());

        $article = $gen->generate([
            'lang' => $lang,
            'keywords' => $keywords,
            'paragraphs' => $paragraphs,
            'faqCount' => $faqCount,
            'styleFlags' => $styleFlags,
            'specialRequirements' => $special,
            'minSentencesPerParagraph' => $minSent,
            'pmidPolicy' => $pmidPolicy
        ], $refs);

        Logger::info('Generated article', ['lang'=>$lang,'kw'=>$keywords,'pmids'=>array_column($refs,'pmid')]);
        Http::json($article, 200);
    }
}