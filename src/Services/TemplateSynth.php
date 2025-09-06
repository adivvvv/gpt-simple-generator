<?php
// src/Services/TemplateSynth.php
declare(strict_types=1);

namespace App\Services;

final class TemplateSynth
{
    public function buildBundle(array $plan, string $lang = 'en'): array
    {
        $prefix = $plan['prefix'];
        $p      = $plan['palette'];
        $t      = $plan['type_scale'];
        $l      = $plan['layout'];
        $copy   = $plan['copy'];

        // i18n labels for static UI strings
        $L  = $this->i18n($lang);
        // Pre-escaped variants for safe literal injection into generated templates
        $Le = [];
        foreach ($L as $k => $v) { $Le[$k] = htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
        $L_shop = $this->phpStr($L['shop_now']); // for use inside single-quoted PHP strings
        $L_disclaimer_full = $this->phpStr($L['disclaimer_full']);

        $files = [];

        // partial-icons.php (svg or unicode)
        $files[] = [
            'path'    => '/app/Templates/partial-icons.php',
            'content' => $l['icons'] === 'svg' ? $this->iconsSvg($prefix) : $this->iconsUnicode()
        ];

        // CSS
        $files[] = [
            'path'    => '/public/assets/tailwind.css',
            'content' => $this->cssFromPlan($prefix, $p, $t, $l)
        ];

        // Header / CTA / Footer
        $files[] = ['path'=>'/app/Templates/partial-header.php', 'content'=>$this->headerPhp($prefix, $l, $Le)];
        $files[] = ['path'=>'/app/Templates/partial-cta.php',    'content'=>$this->ctaPhp($prefix, $copy, $L_shop)];
        $files[] = ['path'=>'/app/Templates/partial-footer.php', 'content'=>$this->footerPhp($prefix, $Le, $L_disclaimer_full)];

        // Home + Article
        $files[] = ['path'=>'/app/Templates/home.php',    'content'=>$this->homePhp($prefix, $lang, $copy, $l, $Le, $L_shop)];
        $files[] = ['path'=>'/app/Templates/article.php', 'content'=>$this->articlePhp($prefix, $lang, $Le)];

        return [
            'name'  => $plan['name'],
            'seed'  => $plan['seed'],
            'files' => $files,
            'notes' => [
                "Prose width ≈ {$t['measure_ch']}ch; base {$t['base_px']}px; leading {$t['leading']}.",
                "All inline assets are local; no external fonts or scripts.",
                "Icons: " . ($l['icons'] === 'svg' ? 'inline SVG' : 'Unicode') . "."
            ]
        ];
    }

    private function iconsUnicode(): string
    {
        return <<<'PHP'
<?php
// app/Templates/partial-icons.php (unicode)
function icon(string $name): string {
    $map = [
        'arrow-right'   => '→',
        'chevron-right' => '›',
        'calendar'      => '📅',
        'tag'           => '#',
        'shop'          => '🛒',
    ];
    return $map[$name] ?? '•';
}
PHP;
    }

    private function iconsSvg(string $prefix): string
    {
        // Small subset of Heroicons paths (MIT). Local-only inline SVG.
        return <<<'PHP'
<?php
// app/Templates/partial-icons.php (svg)
function icon(string $name, string $cls = ''): string {
    $paths = [
        'arrow-right'   => 'M4.5 12h15m0 0-6-6m6 6-6 6',
        'chevron-right' => 'M9 18l6-6-6-6',
        'calendar'      => 'M6.75 3v2.25M17.25 3v2.25M3 8.25h18M4.5 21h15A1.5 1.5 0 0021 19.5V7.5A1.5 1.5 0 0019.5 6h-15A1.5 1.5 0 003 7.5v12A1.5 1.5 0 004.5 21z',
        'tag'           => 'M2.25 12l8.25 8.25L21.75 9l-8.25-8.25H8.25L2.25 6.75v5.25z',
        'shop'          => 'M3 7.5l1.5-3h15L21 7.5M4.5 7.5h15V18a1.5 1.5 0 01-1.5 1.5h-12A1.5 1.5 0 014.5 18V7.5z',
    ];
    if (!isset($paths[$name])) return '<span>•</span>';
    $d = $paths[$name];
    $cls = $cls ?: 'icon';
    return '<svg class="'.$cls.'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" width="18" height="18" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="'.$d.'"/></svg>';
}
PHP;
    }

    private function cssFromPlan(string $pre, array $p, array $t, array $l): string
    {
        // Accessible hover color (slightly darker accent).
        $accentHover = $this->darkenHex($p['accent'], 0.85);

        $card = [
            'soft'     => 'background:var(--cw-card); border:1px solid var(--cw-border); border-radius:.75rem; padding:1rem;',
            'outlined' => 'background:#fff; border:2px solid var(--cw-border); border-radius:.75rem; padding:1rem;',
            'lined'    => 'background:#fff; border-bottom:1px solid var(--cw-border); padding:.75rem 0;',
        ][$l['card_variant']];

        $pag = [
            'minimal' => '.'.$pre.'-page-link{padding:.35rem .55rem;border:1px solid var(--cw-border);border-radius:.375rem}',
            'pill'    => '.'.$pre.'-page-link{padding:.4rem .7rem;border:1px solid var(--cw-border);border-radius:999px}',
            'boxed'   => '.'.$pre.'-page-link{padding:.45rem .7rem;border:1px solid var(--cw-border);border-radius:.5rem;box-shadow:0 1px 0 rgba(0,0,0,.04)}',
        ][$l['pagination_variant']];

        $headerRail = $l['header_variant'] === 'rail' ? "
.$pre-header-rail{border-top:1px solid var(--cw-border); background:#fff}
.$pre-rail-label{display:inline-block; font-weight:600; padding:.5rem 1rem}
.$pre-rail-list{display:inline-flex; gap:.75rem; overflow:auto; vertical-align:middle; padding:.5rem 1rem .75rem 0}
.$pre-rail-item{white-space:nowrap; text-decoration:none; color:var(--cw-muted)}
.$pre-rail-item:hover{color:var(--cw-fg); text-decoration:underline}
" : '';

        $hero = [
            'center-thin'  => ".$pre-hero{text-align:center}.{$pre}-hero-title{margin:0 0 .4rem}.{$pre}-hero-sub{margin:0 auto;max-width:".$t['measure_ch']."ch}",
            'left-stacked' => ".$pre-hero{text-align:left}.{$pre}-hero-sub{max-width:".$t['measure_ch']."ch}",
            'boxed'        => ".$pre-hero{border:1px solid var(--cw-border); border-radius:.75rem; padding:1rem; background:#fff}.{$pre}-hero-sub{max-width:".$t['measure_ch']."ch}",
        ][$l['hero_variant']];

        return <<<CSS
:root{
  --cw-bg: {$p['bg']};
  --cw-card: {$p['card']};
  --cw-fg: {$p['fg']};
  --cw-muted: {$p['muted']};
  --cw-accent: {$p['accent']};
  --cw-accent-ink: {$p['accent_ink']};
  --cw-border: {$p['border']};
}
*{box-sizing:border-box}
html{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans","Helvetica Neue",Arial,"Apple Color Emoji","Segoe UI Emoji"; line-height:{$t['leading']}}
body.$pre-body{margin:0;color:var(--cw-fg);background:var(--cw-bg);font-size:{$t['base_px']}px}
.$pre-container{max-width:74rem;margin-inline:auto;padding:1rem}

.$pre-header{border-bottom:1px solid var(--cw-border); background:#fff; position:sticky; top:0; z-index:30}
.$pre-header-bar{display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.75rem 1rem}
.$pre-brand{font-weight:700; text-decoration:none; color:var(--cw-fg); font-size:1.125rem}
.$pre-nav{display:flex; gap:.75rem; align-items:center}
.$pre-nav-link{color:var(--cw-muted); text-decoration:none; padding:.25rem .5rem; border-radius:.375rem}
.$pre-nav-link:hover{color:var(--cw-fg); background:rgba(14,165,233,.08)}

$headerRail

.$pre-button{display:inline-block;border:1px solid var(--cw-accent);background:var(--cw-accent);color:#fff;font-weight:600;padding:.5rem .9rem;border-radius:.5rem;text-decoration:none}
.$pre-button:hover{background:{$accentHover};border-color:{$accentHover};color:#fff}
.$pre-button-lg{padding:.7rem 1.1rem;font-size:1.05rem}
.$pre-link{color:var(--cw-accent);text-decoration:none;font-weight:500}
.$pre-link:hover{text-decoration:underline}

.$pre-hero{padding:2rem 0 1.25rem;border-bottom:1px solid var(--cw-border)}
.$pre-hero-title{margin:0 0 .5rem; font-size:clamp(1.6rem,2.6vw,2.2rem); line-height:1.22}
.$pre-hero-sub{color:var(--cw-muted)}
$hero

.$pre-section{padding:1.5rem 0}
.$pre-section-title{font-size:1.25rem;margin:0 0 .75rem}

.$pre-list{list-style:none;padding:0;margin:0;display:grid;gap:1rem}
.$pre-card{ $card }
.$pre-card-title{margin:.2rem 0 .35rem;font-size:1.05rem}
.$pre-card-title a{text-decoration:none;color:var(--cw-fg)}
.$pre-card-title a:hover{text-decoration:underline}
.$pre-card-summary{color:var(--cw-muted);max-width:{$t['measure_ch']}ch}
.$pre-card-meta{display:flex;flex-wrap:wrap;gap:.6rem;color:var(--cw-muted);margin-top:.5rem}
.$pre-tag{display:inline-block;border:1px solid var(--cw-border);background:#f1f5f9;border-radius:999px;padding:.1rem .5rem;font-size:.85rem}

.$pre-pagination{display:flex;align-items:center;gap:.35rem;margin-top:1rem;flex-wrap:wrap}
$pag
.$pre-page-link:hover{background:#f8fafc}
.$pre-page-active{background:var(--cw-accent);color:#fff;border-color:var(--cw-accent)}
.$pre-page-disabled{pointer-events:none;opacity:.5}
.$pre-page-ellipsis{padding:.4rem .5rem;color:var(--cw-muted)}

.$pre-article{padding:1.25rem 0}
.$pre-article-header{margin-bottom:1rem}
.$pre-article-title{margin:.2rem 0 .4rem; font-size:clamp(1.4rem,2.3vw,2rem); line-height:1.25}
.$pre-article-summary{color:var(--cw-muted); max-width:{$t['measure_ch']}ch}
.$pre-article-tags{margin-top:.5rem}

.$pre-prose{max-width:{$t['measure_ch']}ch; font-size:1.02rem}
.$pre-prose p{margin:1em 0}
.$pre-prose h2,.$pre-prose h3,.$pre-prose h4{margin:1.6em 0 .6em; line-height:1.25}
.$pre-prose h2{font-size:1.35rem}
.$pre-prose h3{font-size:1.2rem}
.$pre-prose h4{font-size:1.05rem}
.$pre-prose a{color:var(--cw-accent); text-decoration:underline}
.$pre-prose ul,.$pre-prose ol{padding-left:1.25rem}
.$pre-prose blockquote{border-left:3px solid var(--cw-border);margin:1em 0;padding:.25rem .9rem;color:var(--cw-muted)}
.$pre-disclaimer{margin-top:1rem;color:var(--cw-muted);font-size:.95rem}

.$pre-refs{margin-top:1.5rem}
.$pre-refs-list{max-width:{$t['measure_ch']}ch}

/* FAQ */
.$pre-faqs{margin-top:1.5rem}
.$pre-faq{border:1px solid var(--cw-border);border-radius:.5rem;background:#fff;margin:.6rem 0;padding:.4rem .6rem}
.$pre-faq summary{cursor:pointer;font-weight:600}
.$pre-faq p{margin:.5rem 0 0}

.$pre-cta{background:linear-gradient(180deg,#ffffff,#f8fafc); border-top:1px solid var(--cw-border); border-bottom:1px solid var(--cw-border)}
.$pre-cta-box{max-width:{$t['measure_ch']}ch; margin:0 auto; padding:1rem 0; text-align:left}
.$pre-cta-title{margin:.3rem 0}
.$pre-cta-copy{color:var(--cw-muted); margin:.4rem 0 1rem}

.$pre-footer{margin-top:2rem;border-top:1px solid var(--cw-border);background:#fff}
.$pre-footer-inner{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between;padding:1rem 0}
.$pre-footer-nav{display:flex;flex-wrap:wrap;gap:.6rem}
.$pre-footer-link{color:var(--cw-muted);text-decoration:none;padding:.25rem .4rem;border-radius:.375rem}
.$pre-footer-link:hover{color:var(--cw-fg);background:#f1f5f9}
.$pre-footer-note{color:var(--cw-muted);margin:0}

.icon{vertical-align:middle}
@media (max-width:640px){ .{$pre}-rail-label{display:none} }
CSS;
    }

    private function headerPhp(string $pre, array $l, array $Le): string
    {
        $rail = $l['header_variant'] === 'rail' ? <<<HTML
  <?php if (\$latest): ?>
  <div class="$pre-header-rail" aria-label="{$Le['latest_aria']}">
    <span class="$pre-rail-label">{$Le['latest']}:</span>
    <div class="$pre-rail-list">
      <?php foreach (\$latest as \$p): ?>
        <a class="$pre-rail-item" href="\$base/<?=htmlspecialchars(\$p['slug'] ?? '')?>"><?=htmlspecialchars(\$p['title'] ?? '')?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
HTML : '';

        return <<<PHP
<?php
/** @var array \$config */
\$site = \$config['site_name'] ?? 'CamelWay';
\$shop = \$config['shop_url']  ?? 'https://camelway.eu/';
\$base = \$config['base_url']  ?? '/';

/* Where is data? Allow settings.php override via 'data_dir'. */
\$dataOverride = isset(\$config['data_dir']) && is_string(\$config['data_dir']) && \$config['data_dir'] !== '' ? rtrim(\$config['data_dir'], '/')
              : null;

/* Robust latest posts loader (works with multiple layouts & overrides) */
\$latest = [];
\$root1  = realpath(dirname(__DIR__, 2)); // project root
\$candidates = [];
if (\$dataOverride) {
    \$candidates[] = \$dataOverride . '/posts.json';
}
if (\$root1) {
    \$candidates[] = \$root1 . '/data/posts.json';        // current location
    \$candidates[] = \$root1 . '/data/posts/posts.json';  // legacy placeholder path
}
\$found = false;
foreach (\$candidates as \$c) {
    if (is_file(\$c)) {
        \$j = json_decode((string)file_get_contents(\$c), true);
        if (is_array(\$j) && !empty(\$j['posts']) && is_array(\$j['posts'])) {
            \$latest = array_slice(\$j['posts'], 0, 10);
            \$found = true;
            break;
        }
    }
}
if (!\$found) {
    \$dirs = [];
    if (\$dataOverride) \$dirs[] = \$dataOverride . '/posts';
    if (\$root1)        \$dirs[] = \$root1 . '/data/posts';
    foreach (\$dirs as \$dir) {
        if (is_dir(\$dir)) {
            \$tmp = [];
            foreach (glob(\$dir.'/*.json') as \$f) {
                \$x = json_decode((string)file_get_contents(\$f), true);
                if (!is_array(\$x)) continue;
                \$tmp[] = [
                    'title' => (string)(\$x['title'] ?? basename(\$f, '.json')),
                    'slug'  => (string)(\$x['slug']  ?? basename(\$f, '.json')),
                ];
            }
            if (\$tmp) {
                \$latest = array_slice(\$tmp, 0, 10);
                break;
            }
        }
    }
}
?>
<header class="$pre-header">
  <div class="$pre-container $pre-header-bar">
    <a class="$pre-brand" href="<?=\$base?>"><?=htmlspecialchars(\$site)?></a>
    <nav class="$pre-nav">
      <a class="$pre-nav-link" href="<?=\$base?>">{$Le['home']}</a>
      <a class="$pre-nav-link" href="<?=\$base?>?page=1#recent">{$Le['recent']}</a>
      <a class="$pre-button" href="<?=\$shop?>">{$Le['shop_now']} <?=(function_exists('icon') ? icon('arrow-right') : '→')?></a>
    </nav>
  </div>
  {$rail}
</header>
PHP;
    }

    private function ctaPhp(string $pre, array $copy, string $L_shop): string
    {
        // Title/copy now configurable via settings.php; safe fallbacks remain.
        return <<<PHP
<?php /** @var array \$config */ \$shop = \$config['shop_url'] ?? 'https://camelway.eu/'; ?>
<section class="$pre-cta">
  <div class="$pre-container">
    <div class="$pre-cta-box">
      <h2 class="$pre-cta-title"><?=htmlspecialchars(\$config['cta_title'] ?? 'Premium Camel Milk Powder')?></h2>
      <p class="$pre-cta-copy"><?=htmlspecialchars(\$config['cta_copy'] ?? 'Hypoallergenic, lactoferrin-rich nutrition — loved across Europe.')?></p>
      <a class="$pre-button $pre-button-lg" href="<?=\$shop?>"><?=htmlspecialchars(\$config['cta_label'] ?? (\$copy['cta_label'] ?? '{$L_shop}'))?> <?=(function_exists('icon') ? icon('arrow-right') : '→')?></a>
    </div>
  </div>
</section>
PHP;
    }

    private function footerPhp(string $pre, array $Le, string $L_disclaimer_full): string
    {
        return <<<PHP
<?php /** @var array \$config */
\$base = \$config['base_url'] ?? '/';
\$links = \$config['footer_links'] ?? [
  ['label'=>'About', 'href'=>\$base.'/about'],
  ['label'=>'Contact','href'=>\$base.'/contact'],
  ['label'=>'Privacy','href'=>\$base.'/privacy'],
];
?>
<footer class="$pre-footer">
  <div class="$pre-container $pre-footer-inner">
    <nav class="$pre-footer-nav">
      <?php foreach (\$links as \$l): ?>
        <a class="$pre-footer-link" href="<?=htmlspecialchars(\$l['href'])?>"><?=htmlspecialchars(\$l['label'])?></a>
      <?php endforeach; ?>
    </nav>
    <p class="$pre-footer-note">© <?=date('Y')?> <?=htmlspecialchars(\$config['site_name'] ?? 'CamelWay')?> — <?=htmlspecialchars('{$L_disclaimer_full}')?>.</p>
  </div>
</footer>
PHP;
    }

    private function homePhp(string $pre, string $lang, array $copy, array $l, array $Le, string $L_shop): string
    {
        return <<<PHP
<?php require __DIR__.'/partial-icons.php'; require __DIR__.'/partial-header.php';
/** @var array \$config */

/** Pagination + posts */
\$perPage = (int)(\$config['posts_per_page'] ?? 20);
if (\$perPage <= 0) \$perPage = 20; // safety
\$page    = max(1, (int)(\$_GET['page'] ?? 1));

/* Where is data? Allow settings.php override via 'data_dir'. */
\$dataOverride = isset(\$config['data_dir']) && is_string(\$config['data_dir']) && \$config['data_dir'] !== '' ? rtrim(\$config['data_dir'], '/')
              : null;

/* Robust posts index loader (supports overrides; scans as fallback) */
\$all = [];
\$root1  = realpath(dirname(__DIR__, 2)); // project root
\$candidates = [];
if (\$dataOverride) {
  \$candidates[] = \$dataOverride . '/posts.json';
}
if (\$root1) {
  \$candidates[] = \$root1 . '/data/posts.json';
  \$candidates[] = \$root1 . '/data/posts/posts.json';
}

foreach (\$candidates as \$c) {
  if (is_file(\$c)) {
    \$j = json_decode((string)file_get_contents(\$c), true);
    if (is_array(\$j) && !empty(\$j['posts']) && is_array(\$j['posts'])) {
      \$all = \$j['posts'];
      break;
    }
  }
}
if (!\$all) {
  // Final fallback: scan post files and build a minimal index
  \$dirs = [];
  if (\$dataOverride) \$dirs[] = \$dataOverride . '/posts';
  if (\$root1)        \$dirs[] = \$root1 . '/data/posts';
  foreach (\$dirs as \$dir) {
    if (is_dir(\$dir)) {
      \$tmp = [];
      foreach (glob(\$dir.'/*.json') as \$f) {
        \$x = json_decode((string)file_get_contents(\$f), true);
        if (!is_array(\$x)) continue;
        \$tmp[] = [
          'title'        => (string)(\$x['title'] ?? basename(\$f, '.json')),
          'slug'         => (string)(\$x['slug']  ?? basename(\$f, '.json')),
          'summary'      => (string)(\$x['summary'] ?? ''),
          'tags'         => (array) (\$x['tags'] ?? []),
          'published_at' => (string)(\$x['published_at'] ?? date('Y-m-d', @filemtime(\$f) ?: time())),
        ];
      }
      if (\$tmp) {
        usort(\$tmp, fn(\$a,\$b) => strcmp((\$b['published_at'] ?? '').(\$b['slug'] ?? ''), (\$a['published_at'] ?? '').(\$a['slug'] ?? '')));
        \$all = \$tmp;
        break;
      }
    }
  }
}

\$total = count(\$all);
\$start = (\$page - 1) * \$perPage;
\$posts = array_slice(\$all, \$start, \$perPage);
\$totalPages = max(1, (int)ceil(max(1, \$total) / \$perPage));

/** Build a page link */
\$pagelink = fn (int \$p): string => '?page=' . max(1, \$p) . '#recent';
\$rssHref  = (\$config['base_url'] ?? '').'/rss.xml';
\$atomHref = (\$config['base_url'] ?? '').'/atom.xml';
\$cssver   = @filemtime(__DIR__ . '/../../public/assets/tailwind.css') ?: time();

/** JSON-LD for homepage: WebSite + ItemList (latest 20) */
\$siteJsonLd = [
  '@context' => 'https://schema.org',
  '@type'    => 'WebSite',
  'name'     => (string)(\$config['site_name'] ?? 'CamelWay'),
  'url'      => (string)(\$config['base_url'] ?? '/'),
];
\$latestList = array_slice(\$all, 0, 20);
\$items = [];
foreach (\$latestList as \$i => \$pItem) {
  \$items[] = [
    '@type'    => 'ListItem',
    'position' => \$i + 1,
    'url'      => (string)((\$config['base_url'] ?? '').'/'.(\$pItem['slug'] ?? '')),
    'name'     => (string)(\$pItem['title'] ?? ''),
  ];
}
\$listJsonLd = ['@context'=>'https://schema.org','@type'=>'ItemList','itemListElement'=>\$items];
?>
<!doctype html>
<html lang="<?=htmlspecialchars(\$config['lang'] ?? '$lang')?>">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=htmlspecialchars(\$config['site_name'] ?? 'CamelWay')?></title>
  <meta name="description" content="Evidence-first articles about camel milk — readable, fast, and text-only.">
  <link rel="canonical" href="<?=htmlspecialchars((\$config['base_url'] ?? '/'))?>">
  <link rel="stylesheet" href="/assets/tailwind.css?v=<?=rawurlencode((string)\$cssver)?>">
  <link rel="alternate" type="application/rss+xml"  title="<?=htmlspecialchars((\$config['site_name'] ?? 'CamelWay')).' RSS'?>"  href="<?=htmlspecialchars(\$rssHref)?>">
  <link rel="alternate" type="application/atom+xml" title="<?=htmlspecialchars((\$config['site_name'] ?? 'CamelWay')).' Atom'?>" href="<?=htmlspecialchars(\$atomHref)?>">
  <script type="application/ld+json"><?=json_encode(\$siteJsonLd, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?></script>
  <script type="application/ld+json"><?=json_encode(\$listJsonLd, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?></script>
</head>
<body class="$pre-body">
  <main class="$pre-container">
    <!-- Hero -->
    <section class="$pre-hero">
      <h1 class="$pre-hero-title"><?=htmlspecialchars(\$config['hero_title'] ?? (\$copy['hero_title'] ?? 'Camel Milk, Clearly Explained'))?></h1>
      <p class="$pre-hero-sub"><?=htmlspecialchars(\$config['hero_subtitle'] ?? (\$copy['hero_subtitle'] ?? 'Research-summarized, readable articles. No images, no tracking — just fast, accessible pages.'))?></p>
      <div class="$pre-hero-actions" style="margin-top:1rem;display:flex;gap:.75rem;align-items:center">
        <a class="$pre-button $pre-button-lg" href="<?=\$config['shop_url'] ?? 'https://camelway.eu/'?>"><?=htmlspecialchars(\$config['cta_label'] ?? (\$copy['cta_label'] ?? '{$L_shop}'))?> <?=(function_exists('icon') ? icon('arrow-right') : '→')?></a>
        <a class="$pre-link" href="#recent">{$Le['browse_recent']}</a>
      </div>
    </section>

    <!-- Recent list -->
    <section id="recent" class="$pre-section">
      <h2 class="$pre-section-title">{$Le['recent_articles']}</h2>
      <?php if (!\$posts): ?>
        <p>{$Le['no_posts']}</p>
      <?php else: ?>
        <ol class="$pre-list">
          <?php foreach (\$posts as \$p): ?>
            <li class="$pre-card">
              <h3 class="$pre-card-title">
                <a href="<?=(\$config['base_url'] ?? '').'/'.htmlspecialchars(\$p['slug'] ?? '')?>"><?=htmlspecialchars(\$p['title'] ?? '')?></a>
              </h3>
              <?php if (!empty(\$p['summary'])): ?><p class="$pre-card-summary"><?=htmlspecialchars(\$p['summary'])?></p><?php endif; ?>
              <div class="$pre-card-meta">
                <span><?=(function_exists('icon') ? icon('calendar') : '📅')?> <?=htmlspecialchars(\$p['published_at'] ?? '')?></span>
                <?php if (!empty(\$p['tags']) && is_array(\$p['tags'])): ?>
                  <span>
                    <?php foreach (array_slice(\$p['tags'], 0, 3) as \$t): ?>
                      <span class="$pre-tag"><?=(function_exists('icon') ? icon('tag') : '#')?> <?=htmlspecialchars(\$t)?></span>
                    <?php endforeach; ?>
                  </span>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>

      <!-- Pagination -->
      <?php if (\$totalPages > 1): ?>
      <nav class="$pre-pagination" role="navigation" aria-label="Pagination">
        <a class="$pre-page-link<?= \$page<=1 ? ' $pre-page-disabled' : '' ?>" href="<?=\$page<=1?'#':\$pagelink(\$page-1)?>">{$Le['previous']}</a>
        <?php
          \$window = 2;
          \$start = max(1, \$page - \$window);
          \$end   = min(\$totalPages, \$page + \$window);
          if (\$start > 1) echo '<span class="$pre-page-ellipsis">…</span>';
          for (\$i=\$start; \$i<=\$end; \$i++) {
            \$cls = '$pre-page-link' . (\$i===\$page ? ' $pre-page-active' : '');
            echo '<a class="'.\$cls.'" href="'.\$pagelink(\$i).'">'.\$i.'</a>';
          }
          if (\$end < \$totalPages) echo '<span class="$pre-page-ellipsis">…</span>';
        ?>
        <a class="$pre-page-link<?= \$page>=\$totalPages ? ' $pre-page-disabled' : '' ?>" href="<?=\$page>=\$totalPages?'#':\$pagelink(\$page+1)?>">{$Le['next']}</a>
      </nav>
      <?php endif; ?>
    </section>

    <!-- CTA moved to the bottom of HOME only -->
    <?php require __DIR__.'/partial-cta.php'; ?>

  </main>
  <?php require __DIR__.'/partial-footer.php'; ?>
</body>
</html>
PHP;
    }

    private function articlePhp(string $pre, string $lang, array $Le): string
    {
        return <<<PHP
<?php require __DIR__.'/partial-icons.php'; require __DIR__.'/partial-header.php';
/** @var array \$post loaded by Router */
\$title   = \$post['title']   ?? 'Article';
\$summary = \$post['summary'] ?? '';
\$body    = \$post['body']    ?? (\$post['body_markdown'] ?? '');
\$tags    = \$post['tags']    ?? [];
\$pmids   = \$post['pmids']   ?? [];
\$faqs    = \$post['faq'] ?? (\$post['faqs'] ?? []); // can be 'faq' or 'faqs'
\$rssHref  = (\$config['base_url'] ?? '').'/rss.xml';
\$atomHref = (\$config['base_url'] ?? '').'/atom.xml';
\$cssver   = @filemtime(__DIR__ . '/../../public/assets/tailwind.css') ?: time();

/** JSON-LD for article + optional FAQ */
\$url = (string)((\$config['base_url'] ?? '/').'/'.(\$post['slug'] ?? ''));
\$articleJsonLd = [
  '@context'        => 'https://schema.org',
  '@type'           => 'BlogPosting',
  'mainEntityOfPage'=> ['@type'=>'WebPage','@id'=>\$url],
  'headline'        => (string)\$title,
  'description'     => (string)\$summary,
  'datePublished'   => (string)(\$post['published_at'] ?? ''),
  'dateModified'    => (string)(\$post['updated_at'] ?? (\$post['published_at'] ?? '')),
  'publisher'       => ['@type'=>'Organization','name'=>(string)(\$config['site_name'] ?? 'CamelWay')],
  'url'             => \$url
];
\$faqJsonLd = null;
if (!empty(\$faqs) && is_array(\$faqs)) {
    \$qaList = [];
    foreach (\$faqs as \$qa) {
        \$q = trim((string)(\$qa['q'] ?? \$qa['question'] ?? ''));
        \$a = trim((string)(\$qa['a'] ?? \$qa['answer'] ?? ''));
        if (\$q === '' && \$a === '') continue;
        \$qaList[] = [
          '@type' => 'Question',
          'name'  => \$q ?: 'Question',
          'acceptedAnswer' => ['@type'=>'Answer', 'text'=> \$a]
        ];
    }
    if (\$qaList) {
        \$faqJsonLd = ['@context'=>'https://schema.org','@type'=>'FAQPage','mainEntity'=>\$qaList];
    }
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars(\$config['lang'] ?? '$lang')?>">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=htmlspecialchars(\$title)?> — <?=htmlspecialchars(\$config['site_name'] ?? 'CamelWay')?></title>
  <meta name="description" content="<?=htmlspecialchars(\$summary)?>">
  <link rel="canonical" href="<?=htmlspecialchars(\$url)?>">
  <link rel="stylesheet" href="/assets/tailwind.css?v=<?=rawurlencode((string)\$cssver)?>">
  <link rel="alternate" type="application/rss+xml"  title="<?=htmlspecialchars((\$config['site_name'] ?? 'CamelWay')).' RSS'?>"  href="<?=htmlspecialchars(\$rssHref)?>">
  <link rel="alternate" type="application/atom+xml" title="<?=htmlspecialchars((\$config['site_name'] ?? 'CamelWay')).' Atom'?>" href="<?=htmlspecialchars(\$atomHref)?>">
  <script type="application/ld+json"><?=json_encode(\$articleJsonLd, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?></script>
  <?php if (\$faqJsonLd): ?><script type="application/ld+json"><?=json_encode(\$faqJsonLd, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?></script><?php endif; ?>
</head>
<body class="$pre-body">
  <main class="$pre-container">
    <?php require __DIR__.'/partial-cta.php'; ?>

    <article class="$pre-article">
      <header class="$pre-article-header">
        <h1 class="$pre-article-title"><?=htmlspecialchars(\$title)?></h1>
        <?php if (\$summary): ?><p class="$pre-article-summary"><?=htmlspecialchars(\$summary)?></p><?php endif; ?>
        <?php if (!empty(\$tags)): ?>
          <p class="$pre-article-tags">
            <?php foreach (\$tags as \$t): ?><span class="$pre-tag"><?=(function_exists('icon') ? icon('tag') : '#')?> <?=htmlspecialchars(\$t)?></span><?php endforeach; ?>
          </p>
        <?php endif; ?>
      </header>

      <div class="$pre-prose"><?php echo \$body; ?></div>

      <?php if (!empty(\$faqs) && is_array(\$faqs)): ?>
      <section class="$pre-faqs">
        <h2 class="$pre-section-title">{$Le['faq']}</h2>
        <?php foreach (\$faqs as \$qa):
          \$q = trim((string)(\$qa['q'] ?? \$qa['question'] ?? ''));
          \$a = trim((string)(\$qa['a'] ?? \$qa['answer'] ?? ''));
          if (\$q === '' && \$a === '') continue;
        ?>
          <details class="$pre-faq">
            <summary class="$pre-faq-q"><?=htmlspecialchars(\$q !== '' ? \$q : 'Question')?></summary>
            <?php if (\$a !== ''): ?><div class="$pre-faq-a"><p><?=htmlspecialchars(\$a)?></p></div><?php endif; ?>
          </details>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

      <?php if (!empty(\$pmids) && is_array(\$pmids)): ?>
      <section class="$pre-refs">
        <h2 class="$pre-section-title">{$Le['refs']}</h2>
        <ol class="$pre-refs-list">
          <?php foreach (\$pmids as \$pm): ?>
            <li>
              <a href="<?= 'https://pubmed.ncbi.nlm.nih.gov/'.urlencode((string)\$pm).'/' ?>" target="_blank" rel="noopener noreferrer">
                PMID: <?=htmlspecialchars((string)\$pm)?>
              </a>
            </li>
          <?php endforeach; ?>
        </ol>
      </section>
      <?php endif; ?>

      <p class="$pre-disclaimer">{$Le['disclaimer_short']}</p>
    </article>

    <?php require __DIR__.'/partial-cta.php'; ?>
  </main>
  <?php require __DIR__.'/partial-footer.php'; ?>
</body>
</html>
PHP;
    }

    /** Utility: darken a #rrggbb color by factor (0..1). */
    private function darkenHex(string $hex, float $factor): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        $r = max(0, min(255, (int)round(hexdec(substr($hex,0,2)) * $factor)));
        $g = max(0, min(255, (int)round(hexdec(substr($hex,2,2)) * $factor)));
        $b = max(0, min(255, (int)round(hexdec(substr($hex,4,2)) * $factor)));
        return sprintf('#%02x%02x%02x', $r,$g,$b);
    }

    /** Escape for embedding inside single-quoted PHP string literals */
    private function phpStr(string $s): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $s);
    }

    /** Minimal i18n labels for static UI. Extend as needed. */
    private function i18n(string $lang): array
    {
        $map = [
          'en' => [
            'home'=>'Home','recent'=>'Recent','shop_now'=>'Shop Now',
            'browse_recent'=>'Browse recent','recent_articles'=>'Recent articles',
            'no_posts'=>'No posts yet. Come back soon.',
            'previous'=>'Previous','next'=>'Next',
            'faq'=>'FAQ','refs'=>'Referenced studies',
            'disclaimer_short'=>'Educational content. Not medical advice.',
            'disclaimer_full'=>'Educational content only; not medical advice',
            'latest'=>'Latest','latest_aria'=>'Latest articles',
          ],
          'pl' => [
            'home'=>'Strona główna','recent'=>'Najnowsze','shop_now'=>'Kup teraz',
            'browse_recent'=>'Przeglądaj najnowsze','recent_articles'=>'Najnowsze artykuły',
            'no_posts'=>'Brak wpisów. Wróć wkrótce.',
            'previous'=>'Poprzednia','next'=>'Następna',
            'faq'=>'FAQ','refs'=>'Badania (PMID)',
            'disclaimer_short'=>'Treści edukacyjne. To nie jest porada medyczna.',
            'disclaimer_full'=>'Treści edukacyjne; nie stanowią porady medycznej',
            'latest'=>'Najnowsze','latest_aria'=>'Najnowsze artykuły',
          ],
          'de' => [
            'home'=>'Startseite','recent'=>'Neueste','shop_now'=>'Jetzt kaufen',
            'browse_recent'=>'Neueste ansehen','recent_articles'=>'Neueste Artikel',
            'no_posts'=>'Noch keine Beiträge. Schau bald wieder vorbei.',
            'previous'=>'Zurück','next'=>'Weiter',
            'faq'=>'FAQ','refs'=>'Zitierte Studien',
            'disclaimer_short'=>'Bildungsinhalte. Keine medizinische Beratung.',
            'disclaimer_full'=>'Nur Bildungsinhalte; keine medizinische Beratung',
            'latest'=>'Aktuell','latest_aria'=>'Neueste Artikel',
          ],
          'fr' => [
            'home'=>'Accueil','recent'=>'Récents','shop_now'=>'Acheter',
            'browse_recent'=>'Parcourir les récents','recent_articles'=>'Articles récents',
            'no_posts'=>'Aucun article pour le moment. Revenez bientôt.',
            'previous'=>'Précédent','next'=>'Suivant',
            'faq'=>'FAQ','refs'=>'Études référencées',
            'disclaimer_short'=>'Contenu éducatif. Pas un avis médical.',
            'disclaimer_full'=>'Contenu éducatif uniquement ; pas un avis médical',
            'latest'=>'Derniers','latest_aria'=>'Derniers articles',
          ],
          'it' => [
            'home'=>'Home','recent'=>'Recenti','shop_now'=>'Acquista ora',
            'browse_recent'=>'Sfoglia i recenti','recent_articles'=>'Articoli recenti',
            'no_posts'=>'Nessun articolo ancora. Torna presto.',
            'previous'=>'Precedente','next'=>'Successiva',
            'faq'=>'FAQ','refs'=>'Studi citati',
            'disclaimer_short'=>'Contenuti educativi. Non è un consiglio medico.',
            'disclaimer_full'=>'Solo contenuti educativi; non costituisce consiglio medico',
            'latest'=>'Ultimi','latest_aria'=>'Ultimi articoli',
          ],
          'es' => [
            'home'=>'Inicio','recent'=>'Recientes','shop_now'=>'Comprar ahora',
            'browse_recent'=>'Ver recientes','recent_articles'=>'Artículos recientes',
            'no_posts'=>'Aún no hay publicaciones. Vuelve pronto.',
            'previous'=>'Anterior','next'=>'Siguiente',
            'faq'=>'FAQ','refs'=>'Estudios citados',
            'disclaimer_short'=>'Contenido educativo. No es asesoramiento médico.',
            'disclaimer_full'=>'Contenido educativo; no constituye asesoramiento médico',
            'latest'=>'Últimos','latest_aria'=>'Artículos recientes',
          ],
          'sv' => [
            'home'=>'Hem','recent'=>'Senaste','shop_now'=>'Köp nu',
            'browse_recent'=>'Bläddra bland senaste','recent_articles'=>'Senaste artiklar',
            'no_posts'=>'Inga inlägg ännu. Titta snart igen.',
            'previous'=>'Föregående','next'=>'Nästa',
            'faq'=>'FAQ','refs'=>'Refererade studier',
            'disclaimer_short'=>'Utbildande innehåll. Ej medicinsk rådgivning.',
            'disclaimer_full'=>'Endast utbildande innehåll; ingen medicinsk rådgivning',
            'latest'=>'Senaste','latest_aria'=>'Senaste artiklar',
          ],
          'fi' => [
            'home'=>'Etusivu','recent'=>'Uusimmat','shop_now'=>'Osta nyt',
            'browse_recent'=>'Selaa uusimpia','recent_articles'=>'Uusimmat artikkelit',
            'no_posts'=>'Ei vielä artikkeleita. Tule pian takaisin.',
            'previous'=>'Edellinen','next'=>'Seuraava',
            'faq'=>'UKK','refs'=>'Viitatut tutkimukset',
            'disclaimer_short'=>'Koulutuksellista sisältöä. Ei lääketieteellistä neuvontaa.',
            'disclaimer_full'=>'Vain koulutuksellista sisältöä; ei lääketieteellistä neuvontaa',
            'latest'=>'Uusimmat','latest_aria'=>'Uusimmat artikkelit',
          ],
          'nl' => [
            'home'=>'Home','recent'=>'Recent','shop_now'=>'Nu kopen',
            'browse_recent'=>'Blader recente','recent_articles'=>'Recente artikelen',
            'no_posts'=>'Nog geen berichten. Kom snel terug.',
            'previous'=>'Vorige','next'=>'Volgende',
            'faq'=>'FAQ','refs'=>'Gerefereerde studies',
            'disclaimer_short'=>'Educatieve inhoud. Geen medisch advies.',
            'disclaimer_full'=>'Alleen educatieve inhoud; geen medisch advies',
            'latest'=>'Laatste','latest_aria'=>'Laatste artikelen',
          ],
          'cs' => [
            'home'=>'Domů','recent'=>'Nejnovější','shop_now'=>'Koupit nyní',
            'browse_recent'=>'Procházet nejnovější','recent_articles'=>'Nejnovější články',
            'no_posts'=>'Zatím žádné příspěvky. Brzy se vraťte.',
            'previous'=>'Předchozí','next'=>'Další',
            'faq'=>'FAQ','refs'=>'Citované studie',
            'disclaimer_short'=>'Vzdělávací obsah. Nejde o lékařskou radu.',
            'disclaimer_full'=>'Pouze vzdělávací obsah; nejedná se o lékařskou radu',
            'latest'=>'Nejnovější','latest_aria'=>'Nejnovější články',
          ],
        ];
        return $map[$lang] ?? $map['en'];
    }
}