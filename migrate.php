<?php
$dir = __DIR__ . '/pages/';
$files = glob($dir . '*.php');

$successes = [];
$failures = [];

foreach ($files as $file) {
    $basename = basename($file);

    $content = file_get_contents($file);
    if (stripos($content, '<!doctype html>') === false) {
        $failures[] = "$basename (No DOCTYPE)";
        continue;
    }

    // 1. Extract Title
    $title = '';
    if (preg_match('/<title>(.*?)<\/title>/is', $content, $m)) {
        $title = trim($m[1]);
    }

    // 2. Extract Extra CSS
    $extraCss = [];
    preg_match_all('/<link[^>]+href=["\']([^"\']+)["\'][^>]*rel=["\']stylesheet["\'][^>]*>/i', $content, $cssMatches);
    foreach ($cssMatches[1] as $href) {
        if (strpos($href, 'bootstrap') === false && strpos($href, 'font-awesome') === false && strpos($href, 'theme.css') === false && strpos($href, 'fonts.googleapis') === false) {
            $extraCss[] = $href;
        }
    }

    // 3. Extract inline style
    $inlineStyle = '';
    if (preg_match('/<style[^>]*>(.*?)<\/style>/is', $content, $m)) {
        $inlineStyle = trim($m[1]);
    }

    // 4. Extract Extra Head JS
    $extraHeadJs = [];
    $headPart = substr($content, 0, strpos($content, '</head>'));
    preg_match_all('/<script[^>]+src=["\']([^"\']+)["\'][^>]*><\/script>/i', $headPart, $jsMatches);
    foreach ($jsMatches[1] as $src) {
        if (strpos($src, 'bootstrap') === false && strpos($src, 'sidebar.js') === false) {
            $extraHeadJs[] = $src;
        }
    }

    // 5. Build Top Replacement
    $topPhp = "<?php\n\$pageTitle = '" . addslashes($title) . "';\n";
    if (!empty($extraCss)) {
        $topPhp .= "\$extraCss = " . var_export($extraCss, true) . ";\n";
    }
    if (!empty($extraHeadJs)) {
        $topPhp .= "\$extraHeadJs = " . var_export($extraHeadJs, true) . ";\n";
    }
    $topPhp .= "require_once '../includes/layout_header.php';\n?>\n";
    if (!empty($inlineStyle)) {
        $topPhp .= "<style>\n" . $inlineStyle . "\n</style>\n";
    }

    // Find the cut point for Top
    // We want to cut from <!DOCTYPE html> down to <div id="page-content-wrapper">
    // and keep the line following it if it's <div class="container-fluid...">
    $wrapperStart = strpos($content, '<div id="page-content-wrapper">');
    if ($wrapperStart === false) {
        $failures[] = "$basename (No page-content-wrapper)";
        continue;
    }
    
    // Find the end of the div that opens container-fluid
    $containerFluidStart = strpos($content, '<div class="container-fluid', $wrapperStart);
    if ($containerFluidStart === false) {
        $failures[] = "$basename (No container-fluid)";
        continue;
    }
    
    // Find the end of that div line
    $containerFluidLineEnd = strpos($content, '>', $containerFluidStart) + 1;
    
    $cutPointTop = $containerFluidLineEnd;
    
    // Remove everything from <!DOCTYPE html> to the cut point.
    $doctypePos = stripos($content, '<!doctype html>');
    $contentBeforeDoctype = substr($content, 0, $doctypePos);
    
    $bottomPart = substr($content, $cutPointTop);
    
    // 6. Extract Bottom JS
    $extraJs = [];
    $scriptSection = substr($bottomPart, strrpos($bottomPart, '</div>')); // roughly where scripts start
    preg_match_all('/<script[^>]+src=["\']([^"\']+)["\'][^>]*><\/script>/i', $scriptSection, $jsMatches2);
    foreach ($jsMatches2[1] as $src) {
        if (strpos($src, 'bootstrap') === false && strpos($src, 'sidebar.js') === false && strpos($src, 'background_animation.js') === false) {
            $extraJs[] = $src;
        }
    }
    
    // 7. Build Bottom Replacement
    $bottomPhp = "<?php\n";
    if (!empty($extraJs)) {
        $bottomPhp .= "\$extraJs = " . var_export($extraJs, true) . ";\n";
    }
    $bottomPhp .= "require_once '../includes/layout_footer.php';\n?>";

    // Find where to cut the bottom.
    // We want to cut out `<div id="spinner-overlay">...</div>` and `<script...></script>` and `</body></html>`
    $spinnerPos = stripos($bottomPart, '<div id="spinner-overlay"');
    $bootstrapPos = stripos($bottomPart, '<script src="https://cdn.jsdelivr.net/npm/bootstrap');
    
    $cutPointBottom = false;
    if ($spinnerPos !== false) {
        $cutPointBottom = $spinnerPos;
    } elseif ($bootstrapPos !== false) {
        $cutPointBottom = $bootstrapPos;
    }
    
    if ($cutPointBottom !== false) {
        $middleContent = substr($bottomPart, 0, $cutPointBottom);
        
        // Remove old <?php display_message(); since it's now in layout_header
        $middleContent = preg_replace('/<\?php\s+display_message\(\);\s*(?:\/\/[^\n]*)?\?>/is', '', $middleContent);
        
        $newContent = rtrim($contentBeforeDoctype) . "\n\n" . $topPhp . "\n" . ltrim($middleContent) . "\n" . $bottomPhp . "\n";
        
        file_put_contents($file, $newContent);
        $successes[] = $basename;
    } else {
        $failures[] = "$basename (Bottom cut point not found)";
    }
}

echo "Success: " . count($successes) . "\n";
echo "Failures: " . count($failures) . "\n";
if (!empty($failures)) {
    echo implode("\n", $failures) . "\n";
}
