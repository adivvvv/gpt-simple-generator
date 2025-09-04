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

        $files = [];

        // partial-icons.php (svg or unicode)
        $files[] = [
            'path' => '/app/Templates/partial-icons.php',
            'content' => $l['icons'] === 'svg' ? $this->iconsSvg($prefix) : $this->iconsUnicode()
        ];

        // CSS
        $files[] = [
            'path' => '/public/assets/tailwind.css',
            'content' => $this->cssFromPlan($prefix, $p, $t, $l)
        ];

        // Header / CTA / Footer
        $files[] = ['path'=>'/app/Templates/partial-header.php', 'content'=>$this->headerPhp($prefix, $l)];
        $files[] = ['path'=>'/app/Templates/partial-cta.php',    'content'=>$this->ctaPhp($prefix, $copy)];
        $files[] = ['path'=>'/app/Templates/partial-footer.php', 'content'=>$this->footerPhp($prefix)];

        // Home + Article
        $files[] = ['path'=>'/app/Templates/home.php',    'content'=>$this->homePhp($prefix, $lang, $copy, $l)];
        $files[] = ['path'=>'/app/Templates/article.php', 'content'=>$this->articlePhp($prefix, $lang)];

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
        'arrow-right' => '→',
        'chevron-right' => '›',
        'calendar' => '📅',
        'tag' => '#',
        'shop' => '🛒',
    ];
    return $map[$name] ?? '•';
}
PHP;
    }

    private function iconsSvg(string $prefix): string
    {
        // Small subset of Heroicons paths (MIT). Local-only inline SVG. https://github.com/tailwindlabs/heroicons
        return <<<'PHP'
<?php
// app/Templates/partial-icons.php (svg)
function icon(string $name, string $cls = ''): string {
    $paths = [
        'arrow-right' => 'M4.5 12h15m0 0-6-6m6 6-6 6',
        'chevron-right' => 'M9 18l6-6-6-6',
        'calendar' => 'M6.75 3v2.25M17.25 3v2.25M3 8.25h18M4.5 21h15A1.5 1.5 0 0021 19.5V7.5A1.5 1.5 0 0019.5 6h-15A1.5 1.5 0 003 7.5v12A1.5 1.5 0 004.5 21z',
        'tag' => 'M2.25 12l8.25 8.25L21.75 9l-8.25-8.25H8.25L2.25 6.75v5.25z',
        'shop' => 'M3 7.5l1.5-3h15L21 7.5M4.5 7.5h15V18a1.5 1.5 0 01-1.5 1.5h-12A1.5 1.5 0 014.5 18V7.5z',
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
        // Card and pagination variations (simple) + prose width & line-height tuned for readability.
        // Typography defaults inspired by Tailwind Typography plugin (local, no build). https://github.com/tailwindlabs/tailwindcss-typography
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
.$pre-button:hover{background:var(--cw-accent-ink);border-color:var(--cw-accent-ink)}
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

    private function headerPhp(string $pre, array $l): string
    {
        $rail = $l['header_variant'] === 'rail' ? <<<HTML
  <?php if (\$latest): ?>
  <div class="$pre-header-rail" aria-label="Latest articles">
    <span class="$pre-rail-label">Latest:</span>
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

// Latest posts for header rail
\$postsIdx = __DIR__ . '/../../data/posts.json';
\$latest = [];
if (is_file(\$postsIdx)) {
    \$json = json_decode((string)file_get_contents(\$postsIdx), true);
    \$latest = array_slice(\$json['posts'] ?? [], 0, 10);
}
?>
<header class="$pre-header">
  <div class="$pre-container $pre-header-bar">
    <a class="$pre-brand" href="<?=\$base?>"><?=htmlspecialchars(\$site)?></a>
    <nav class="$pre-nav">
      <a class="$pre-nav-link" href="<?=\$base?>">Home</a>
      <a class="$pre-nav-link" href="<?=\$base?>?page=1#recent">Recent</a>
      <a class="$pre-button" href="<?=\$shop?>">Shop Now <?=(function_exists('icon') ? icon('arrow-right') : '→')?></a>
    </nav>
  </div>
  {$rail}
</header>
PHP;
    }

    private function ctaPhp(string $pre, array $copy): string
    {
        return <<<PHP
<?php /** @var array \$config */ \$shop = \$config['shop_url'] ?? 'https://camelway.eu/'; ?>
<section class="$pre-cta">
  <div class="$pre-container">
    <div class="$pre-cta-box">
      <h2 class="$pre-cta-title">Premium Camel Milk Powder</h2>
      <p class="$pre-cta-copy">Hypoallergenic, lactoferrin-rich nutrition — loved across Europe.</p>
      <a class="$pre-button $pre-button-lg" href="<?=\$shop?>"><?=htmlspecialchars(\$config['cta_label'] ?? (\$copy['cta_label'] ?? 'Shop Now'))?> <?=(function_exists('icon') ? icon('arrow-right') : '→')?></a>
    </div>
  </div>
</section>
PHP;
    }

    private function footerPhp(string $pre): string
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
    <p class="$pre-footer-note">© <?=date('Y')?> CamelWay — Educational content only; not medical advice.</p>
  </div>
</footer>
PHP;
    }

    private function homePhp(string $pre, string $lang, array $copy, array $l): string
    {
        return <<<PHP
<?php require __DIR__.'/partial-icons.php'; require __DIR__.'/partial-header.php';
/** Pagination + posts */
\$perPage = (int)(\$config['posts_per_page'] ?? 20);
\$page    = max(1, (int)(\$_GET['page'] ?? 1));
\$idxFile = __DIR__ . '/../../data/posts.json';
\$posts   = [];
\$total   = 0;

if (is_file(\$idxFile)) {
  \$json = json_decode((string)file_get_contents(\$idxFile), true);
  \$all  = \$json['posts'] ?? [];
  \$total = count(\$all);
  \$start = (\$page - 1) * \$perPage;
  \$posts = array_slice(\$all, \$start, \$perPage);
}
\$totalPages = max(1, (int)ceil(\$total / \$perPage));

function {$pre}_pagelink(int \$p): string { \$p=max(1,\$p); return '?page='.\$p.'#recent'; }
?>
<!doctype html>
<html lang="<?=htmlspecialchars(\$config['lang'] ?? '$lang')?>">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=htmlspecialchars(\$config['site_name'] ?? 'CamelWay')?></title>
  <meta name="description" content="Evidence-first articles about camel milk — readable, fast, and text-only.">
  <link rel="canonical" href="<?=htmlspecialchars((\$config['base_url'] ?? '/'))?>">
  <link rel="stylesheet" href="/assets/tailwind.css">
</head>
<body class="$pre-body">
  <main class="$pre-container">
    <!-- Hero -->
    <section class="$pre-hero">
      <h1 class="$pre-hero-title"><?=htmlspecialchars(\$config['hero_title'] ?? (\$copy['hero_title'] ?? 'Camel Milk, Clearly Explained'))?></h1>
      <p class="$pre-hero-sub"><?=htmlspecialchars(\$config['hero_subtitle'] ?? (\$copy['hero_subtitle'] ?? 'Research-summarized, readable articles. No images, no tracking — just fast, accessible pages.'))?></p>
      <div class="$pre-hero-actions" style="margin-top:1rem;display:flex;gap:.75rem;align-items:center">
        <a class="$pre-button $pre-button-lg" href="<?=\$config['shop_url'] ?? 'https://camelway.eu/'?>"><?=htmlspecialchars(\$config['cta_label'] ?? (\$copy['cta_label'] ?? 'Shop Now'))?> <?=(function_exists('icon') ? icon('arrow-right') : '→')?></a>
        <a class="$pre-link" href="#recent">Browse recent</a>
      </div>
    </section>

    <!-- CTA -->
    <?php require __DIR__.'/partial-cta.php'; ?>

    <!-- Recent list -->
    <section id="recent" class="$pre-section">
      <h2 class="$pre-section-title">Recent articles</h2>
      <?php if (!\$posts): ?>
        <p>No posts yet. Come back soon.</p>
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
        <a class="{$pre}-page-link<?=\$page<=1?' '.$pre.'-page-disabled':''?>" href="<?=\$page<=1?'#':{$pre}_pagelink(\$page-1)?>">Previous</a>
        <?php
          \$window = 2;
          \$start = max(1, \$page - \$window);
          \$end   = min(\$totalPages, \$page + \$window);
          if (\$start > 1) echo '<span class="'.$pre.'-page-ellipsis">…</span>';
          for (\$i=\$start; \$i<=\$end; \$i++) {
            \$cls = '$pre-page-link'.(\$i===\$page?' '.$pre.'-page-active':'');
            echo '<a class="'.\$cls.'" href="'.{$pre}_pagelink(\$i).'">'.\$i.'</a>';
          }
          if (\$end < \$totalPages) echo '<span class="'.$pre.'-page-ellipsis">…</span>';
        ?>
        <a class="{$pre}-page-link<?=\$page>=\$totalPages?' '.$pre.'-page-disabled':''?>" href="<?=\$page>=\$totalPages?'#':{$pre}_pagelink(\$page+1)?>">Next</a>
      </nav>
      <?php endif; ?>
    </section>
  </main>
  <?php require __DIR__.'/partial-footer.php'; ?>
</body>
</html>
PHP;
    }

    private function articlePhp(string $pre, string $lang): string
    {
        return <<<PHP
<?php require __DIR__.'/partial-icons.php'; require __DIR__.'/partial-header.php';
/** @var array \$post loaded by Router */
\$title   = \$post['title']   ?? 'Article';
\$summary = \$post['summary'] ?? '';
\$body    = \$post['body']    ?? (\$post['body_markdown'] ?? '');
\$tags    = \$post['tags']    ?? [];
\$pmids   = \$post['pmids']   ?? [];
?>
<!doctype html>
<html lang="<?=htmlspecialchars(\$config['lang'] ?? '$lang')?>">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=htmlspecialchars(\$title)?> — <?=htmlspecialchars(\$config['site_name'] ?? 'CamelWay')?></title>
  <meta name="description" content="<?=htmlspecialchars(\$summary)?>">
  <link rel="canonical" href="<?=htmlspecialchars((\$config['base_url'] ?? '/').'/'.(\$post['slug'] ?? ''))?>">
  <link rel="stylesheet" href="/assets/tailwind.css">
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

      <?php if (!empty(\$pmids) && is_array(\$pmids)): ?>
      <section class="$pre-refs">
        <h2 class="$pre-section-title">Referenced studies</h2>
        <ol class="$pre-refs-list">
          <?php foreach (\$pmids as \$pm): ?>
            <li><a href="<?= 'https://pubmed.ncbi.nlm.nih.gov/'.urlencode((string)\$pm).'/' ?>">PMID: <?=htmlspecialchars((string)\$pm)?></a></li>
          <?php endforeach; ?>
        </ol>
      </section>
      <?php endif; ?>

      <p class="$pre-disclaimer">Educational content. Not medical advice.</p>
    </article>

    <?php require __DIR__.'/partial-cta.php'; ?>
  </main>
  <?php require __DIR__.'/partial-footer.php'; ?>
</body>
</html>
PHP;
    }
}