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
use App\Services\IdeaStore;

final class GenerateController
{
    public function handle(): void
    {
        $body        = Http::body();
        $lang        = (string)($body['lang'] ?? '');
        $paragraphs  = (int)($body['paragraphs'] ?? 9);
        $faqCount    = (int)($body['faqCount'] ?? 8);
        $styleFlags  = array_values(array_filter((array)($body['styleFlags'] ?? ['human-like','evidence-based'])));
        $special     = (string)($body['specialRequirements'] ?? '');
        $minSent     = (int)($body['minSentencesPerParagraph'] ?? (int)(Env::get('CONTENT_MIN_SENTENCES', '4')));
        $pmidPolicy  = (string)($body['pmidPolicy'] ?? 'auto'); // auto|none|limited

        if (!Validator::lang($lang)) {
            Http::json(['error' => 'Invalid lang'], 422);
        }

        // 1) SUBJECT comes from the ideas pool (always).
        $subject = (string)($body['subject'] ?? '');
        if ($subject === '') {
            $store = new IdeaStore($lang);
            $ideas = $store->list(1, true);
            $subject = (string)($ideas[0]['title'] ?? '');
        }
        if ($subject === '') {
            Http::json(['error' => 'No subject available in ideas pool; seed ideas first.'], 409);
        }

        // 2) KEYWORDS are always just ["camel milk"] as requested.
        $keywords = ['camel milk'];

        // 3) PubMed refs can still be fetched from the (fixed) keyword.
        $pubmed = new PubMedService();
        $refs   = $pubmed->context($lang, $keywords, (int)(Env::get('PUBMED_RETMAX', '12')));

        $gen = new ArticleGenerator(new OpenAIService());

        $article = $gen->generate([
            'lang' => $lang,
            'subject' => $subject,
            'keywords' => $keywords,
            'paragraphs' => $paragraphs,
            'faqCount' => $faqCount,
            'styleFlags' => $styleFlags,
            'specialRequirements' => $special,
            'minSentencesPerParagraph' => $minSent,
            'pmidPolicy' => $pmidPolicy
        ], $refs);

        Logger::info('Generated article', [
            'lang'=>$lang,
            'subject'=>$subject,
            'pmids'=>array_column($refs,'pmid')
        ]);

        Http::json($article, 200);
    }
}