<?php
header('Content-Type: text/html; charset=utf-8');
echo '<h2>Neura — Check Imagick</h2>';

// 1. Extension loaded?
echo '<h3>1. Extension Imagick</h3>';
if (extension_loaded('imagick')) {
    echo '<p style="color:green;">✅ Imagick est chargé</p>';
    $v = Imagick::getVersion();
    echo '<p>Version ImageMagick : ' . $v['versionString'] . '</p>';
    echo '<p>Version module PHP : ' . phpversion('imagick') . '</p>';
} else {
    echo '<p style="color:red;">❌ Imagick N\'EST PAS chargé</p>';
    echo '<p>Fallback GD disponible : ' . (extension_loaded('gd') ? '✅ Oui' : '❌ Non') . '</p>';
}

// 2. Formats supportés
echo '<h3>2. Formats image supportés</h3>';
if (extension_loaded('imagick')) {
    $formats = Imagick::queryFormats();
    $needed = ['JPEG', 'PNG', 'WEBP', 'GIF'];
    foreach ($needed as $fmt) {
        $ok = in_array($fmt, $formats);
        echo '<p>' . ($ok ? '✅' : '❌') . ' ' . $fmt . '</p>';
    }
}

// 3. Fonts / FreeType
echo '<h3>3. Support typographique</h3>';
if (extension_loaded('imagick')) {
    $draw = new ImagickDraw();
    try {
        $draw->setFont('Helvetica');
        echo '<p>✅ setFont() fonctionne (polices système)</p>';
    } catch (Exception $e) {
        echo '<p>⚠️ setFont() erreur : ' . $e->getMessage() . '</p>';
    }

    // Test avec un .ttf custom
    $testFont = __DIR__ . '/assets/fonts/test-font.ttf';
    if (file_exists($testFont)) {
        try {
            $draw2 = new ImagickDraw();
            $draw2->setFont($testFont);
            echo '<p>✅ Polices TTF custom supportées</p>';
        } catch (Exception $e) {
            echo '<p>❌ Polices TTF custom : ' . $e->getMessage() . '</p>';
        }
    } else {
        echo '<p>ℹ️ Pas de police TTF de test trouvée dans /assets/fonts/</p>';
    }
}

// 4. Test de génération d'image
echo '<h3>4. Test génération image</h3>';
if (extension_loaded('imagick')) {
    try {
        $img = new Imagick();
        $img->newImage(1200, 900, new ImagickPixel('#1a1a2e'));
        $img->setImageFormat('jpeg');

        // Overlay
        $overlay = new Imagick();
        $overlay->newImage(1200, 360, new ImagickPixel('#00e5cc'));
        $overlay->evaluateImage(Imagick::EVALUATE_MULTIPLY, 0.15, Imagick::CHANNEL_ALPHA);
        $img->compositeImage($overlay, Imagick::COMPOSITE_OVER, 0, 540);

        // Texte
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('#ffffff'));
        $draw->setFontSize(48);
        $draw->setGravity(Imagick::GRAVITY_CENTER);
        $img->annotateImage($draw, 0, 0, 0, 'Neura - Test Imagick OK');

        // Compression
        $img->setImageCompressionQuality(85);
        $img->stripImage();

        $size = strlen($img->getImageBlob());
        echo '<p style="color:green;">✅ Image 1200x900 générée avec succès</p>';
        echo '<p>Poids : ' . round($size / 1024, 1) . ' Ko</p>';
        echo '<p>Format : JPEG quality 85</p>';

        // Sauvegarder le test
        $testPath = __DIR__ . '/media/test-imagick.jpg';
        $testDir = __DIR__ . '/media';
        if (!is_dir($testDir)) {
            mkdir($testDir, 0755, true);
        }
        $img->writeImage($testPath);
        if (file_exists($testPath)) {
            echo '<p>✅ Écriture fichier OK : <a href="media/test-imagick.jpg" target="_blank">Voir l\'image test</a></p>';
        }

        $img->clear();
        $img->destroy();
    } catch (Exception $e) {
        echo '<p style="color:red;">❌ Erreur génération : ' . $e->getMessage() . '</p>';
    }
}

// 5. Limites mémoire
echo '<h3>5. Limites serveur</h3>';
echo '<p>memory_limit : ' . ini_get('memory_limit') . '</p>';
echo '<p>max_execution_time : ' . ini_get('max_execution_time') . 's</p>';
echo '<p>upload_max_filesize : ' . ini_get('upload_max_filesize') . '</p>';
echo '<p>PHP version : ' . phpversion() . '</p>';

// 6. GD fallback info
echo '<h3>6. GD (fallback)</h3>';
if (extension_loaded('gd')) {
    $gdInfo = gd_info();
    echo '<p>✅ GD version : ' . $gdInfo['GD Version'] . '</p>';
    echo '<p>FreeType : ' . ($gdInfo['FreeType Support'] ? '✅' : '❌') . '</p>';
    echo '<p>JPEG : ' . ($gdInfo['JPEG Support'] ? '✅' : '❌') . '</p>';
    echo '<p>PNG : ' . ($gdInfo['PNG Support'] ? '✅' : '❌') . '</p>';
    echo '<p>WebP : ' . (($gdInfo['WebP Support'] ?? false) ? '✅' : '❌') . '</p>';
} else {
    echo '<p style="color:red;">❌ GD non disponible</p>';
}

echo '<hr><p style="color:#888;font-size:12px;">Neura check — ' . date('Y-m-d H:i:s') . '</p>';
