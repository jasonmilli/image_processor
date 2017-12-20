<?php
    ini_set('memory_limit', '2G');
    set_time_limit(0);

    $pdo = new PDO('mysql:dbname=skynet;host=127.0.0.1', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    define('SEGMENT_NUMBER', 2);
    define('IMAGE_FOCUS', 20);
    define('NUMBER_OF_SQUARES', 100);

    $debug = isset($_REQUEST['debug']) && $_REQUEST['debug'];
    if ($debug) {
        $time = time();
    }
?>
<form>
    <input type="file" name="jpeg" />
    <input type="text" name="answer" />
    <input type="checkbox" name="debug" <?php echo $debug ? 'checked="checked"' : ''; ?> />
    <input type="submit" />
</form>

<?php
    if ($debug) {
        p($_REQUEST, 'Request');
    }

    $boundaries = [
        'minR' => 255,
        'minG' => 255,
        'minB' => 255,
        'maxR' => 0,
        'maxG' => 0,
        'maxB' => 0
    ];

    $image = imagecreatefromjpeg("jpegs/{$_REQUEST['jpeg']}");
    $width = imagesx($image);
    $height = imagesy($image);

    $pixels = [];
    for ($w = 0; $w < $width; $w++) {
        if ($w % IMAGE_FOCUS) {
            continue;
        }

        $pixels[$w / IMAGE_FOCUS] = [];
        for ($h = 0; $h < $height; $h++) {
            if ($h % IMAGE_FOCUS) {
                continue;
            }

            $rgb = imagecolorat($image, $w, $h);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;

            $pixels[$w / IMAGE_FOCUS][$h / IMAGE_FOCUS] = [
                'r' => $r,
                'g' => $g,
                'b' => $b
            ];

            if ($r < $boundaries['minR']) {
                $boundaries['minR'] = $r;
            }
            if ($r > $boundaries['maxR']) {
                $boundaries['maxR'] = $r;
            }
            if ($g < $boundaries['minG']) {
                $boundaries['minG'] = $g;
            }
            if ($g > $boundaries['maxG']) {
                $boundaries['maxG'] = $g;
            }
            if ($b < $boundaries['minB']) {
                $boundaries['minB'] = $b;
            }
            if ($b > $boundaries['maxB']) {
                $boundaries['maxB'] = $b;
            }
        }
    }

    $ranges = [
        'r' => $boundaries['maxR'] - $boundaries['minR'],
        'g' => $boundaries['maxG'] - $boundaries['minG'],
        'b' => $boundaries['maxB'] - $boundaries['minB']
    ];

    $segmentSizes = [
        'r' => round($ranges['r'] / SEGMENT_NUMBER),
        'g' => round($ranges['g'] / SEGMENT_NUMBER),
        'b' => round($ranges['b'] / SEGMENT_NUMBER)
    ];


    if ($debug) {
        p($boundaries, 'Boundaries');
        p($ranges, 'Ranges');
        p($segmentSizes, 'Segment sizes');
    }

    $simplifiedPixels = [];
    foreach ($pixels as $w => $column) {
        $simplifiedPixels[$w] = [];
        foreach ($column as $h => $pixel) {
            $simplifiedPixels[$w][$h] = [
                'r' => $segmentSizes['r'] / 2 + $boundaries['minR'] + $segmentSizes['r'] * floor(($pixel['r'] - $boundaries['minR']) / $segmentSizes['r']),
                'g' => $segmentSizes['g'] / 2 + $boundaries['minG'] + $segmentSizes['g'] * floor(($pixel['g'] - $boundaries['minG']) / $segmentSizes['g']),
                'b' => $segmentSizes['b'] / 2 + $boundaries['minB'] + $segmentSizes['b'] * floor(($pixel['b'] - $boundaries['minB']) / $segmentSizes['b'])
            ];
        }
    }

    if ($debug) {
        $simplifiedImage = imagecreatetruecolor(ceil($width / IMAGE_FOCUS), ceil($height / IMAGE_FOCUS));
        foreach ($simplifiedPixels as $w => $column) {
            foreach ($column as $h => $pixel) {
                imagesetpixel($simplifiedImage, $w, $h, imagecolorallocate($simplifiedImage, $pixel['r'], $pixel['g'], $pixel['b']));
            }
        }

        imagejpeg($simplifiedImage, "simplifiedjpegs/{$_REQUEST['jpeg']}");

        echo "<image src='simplifiedjpegs/{$_REQUEST['jpeg']}' />\n";
    }

    $topSquares = [];
    for ($i = 0; $i < NUMBER_OF_SQUARES; $i++) {
        $maximumRadius = 0;
        $topSquare = null;
        foreach ($simplifiedPixels as $w => $column) {
            foreach ($column as $h => $pixel) {
                $radius = getRadius($w, $h, $pixel, count($simplifiedPixels), count($column), $topSquares, $simplifiedPixels);
                if ($radius > $maximumRadius) {
                    $maximumRadius = $radius;
                    $topSquare = [
                        'w' => $w,
                        'h' => $h,
                        'pixel' => $pixel,
                        'radius' => $radius
                    ];
                }
            }
        }
        $topSquares[] = $topSquare;
    }

    // debug squareimage
    if ($debug) {
        p($topSquares, 'Top ten squares');

        $squareImage = imagecreatetruecolor(ceil($width / IMAGE_FOCUS), ceil($height / IMAGE_FOCUS));
        foreach ($topSquares as $topSquare) {
            for ($w = $topSquare['w'] - $topSquare['radius']; $w <= $topSquare['w'] + $topSquare['radius']; $w++) {
                for ($h = $topSquare['h'] - $topSquare['radius']; $h <= $topSquare['h'] + $topSquare['radius']; $h++) {
                    imagesetpixel($squareImage, $w, $h, imagecolorallocate($squareImage, $topSquare['pixel']['r'], $topSquare['pixel']['g'], $topSquare['pixel']['b']));
                }
            }
        }

        imagejpeg($squareImage, "squarejpegs/{$_REQUEST['jpeg']}");

        echo "<image src='squarejpegs/{$_REQUEST['jpeg']}' />\n";
    }

    // check in db
    //for ($topSquares as $topSquare) {
        

    // insert into db
    if (isset($_REQUEST['answer']) && $_REQUEST['answer']) {
        // Get or insert and get lesson
        $sth = $pdo->prepare(<<<SQL
SELECT `id` FROM `lessons` WHERE `segment_number` = '" . SEGMENT_NUMBER . "' AND `image_focus` = '" . IMAGE_FOCUS . "' AND `number_of_squares` = '" . NUMBER_OF_SQUARES . "';
SQL
        );
        $sth->execute();
        $lesson = $sth->fetch(PDO::FETCH_ASSOC);

        if ($lesson === false) {
            $sth = $pdo->prepare(<<<SQL
INSERT INTO `lessons` VALUES(null, '" . SEGMENT_NUMBER . "', '" . IMAGE_FOCUS . "', '" . NUMBER_OF_SQUARES . "');
SQL
            );
            $sth->execute();

            $lessonId = $pdo->lastInsertId();
        } else {
            $lessonId = $lesson['id'];
        }

        // Get or insert and get question
        $sth = $pdo->prepare(<<<SQL
SELECT * FROM `questions` WHERE `lesson_id` = '{$lessonId}' && `answer` = '{$_REQUEST['answer']}';
SQL
        );
        $sth->execute();
        $question = $sth->fetch(PDO::FETCH_ASSOC);

        if ($question === false) {
            $sth = $pdo->prepare(<<<SQL
INSERT INTO `questions` VALUES(null, '{$lessonId}', '{$_REQUEST['answer']}');
SQL
            );
            $sth->execute();

            $questionId = $pdo->lastInsertId();
        } else {
            $questionId = $question['id'];
        }

        // Insert into hints
        $sth = $pdo->prepare(<<<SQL
INSERT INTO `hints` VALUES(null, ?, ?, ?, ?, ?, ?, ?);
SQL
        );
        foreach ($topSquares as $topSquare) {
            $sth->execute(['questionId', $topSquare['pixel']['r'], $topSquare['pixel']['g'], $topSquare['pixel']['b'], $topSquare['w'], $topSquare['h'], $topSquare['radius']]);
        }
    }





    function getRadius($w, $h, $pixel, $width, $height, $topSquares, $simplifiedPixels, $radius = 0, $maximumRadius = null) {
        // Check not in top ten square already
        if ($maximumRadius === null) {
            foreach ($topSquares as $topSquare) {
                if ($w < $topSquare['w']) {
                    $minW = $topSquare['w'] - $topSquare['radius'] - $w;
                } else {
                    $minW = $w - $topSquare['w'] - $topSquare['radius'];
                }

                if ($h < $topSquare['h']) {
                    $minH = $topSquare['h'] - $topSquare['radius'] - $h;
                } else {
                    $minH = $h - $topSquare['h'] - $topSquare['radius'];
                }

                if ($maximumRadius === null || $maximumRadius > max($minW, $minH)) {
                    $maximumRadius = max($minW, $minH);
                }
            }
        }
        if ($maximumRadius !== null && $maximumRadius <= $radius) {
            return $radius;
        }
        
        $radius++;
        
        // Check in boundaries of image
        if ($w - $radius < 0 || $h - $radius < 0 || $w + $radius >= $width || $h + $radius >= $height) {
            return $radius;
        }

        // Check correct shade
        if (
            !matchPixels($pixel, $simplifiedPixels[$w + $radius][$h])
            || !matchPixels($pixel, $simplifiedPixels[$w - $radius][$h])
            || !matchPixels($pixel, $simplifiedPixels[$w][$h + $radius])
            || !matchPixels($pixel, $simplifiedPixels[$w][$h - $radius])
        ) {
            return $radius - 1;
        }
        for ($distance = 1; $distance <= $radius; $distance++) {
            if (
                !matchPixels($pixel, $simplifiedPixels[$w + $radius][$h + $distance])
                || !matchPixels($pixel, $simplifiedPixels[$w + $radius][$h - $distance])
                || !matchPixels($pixel, $simplifiedPixels[$w - $radius][$h + $distance])
                || !matchPixels($pixel, $simplifiedPixels[$w - $radius][$h - $distance])
                || !matchPixels($pixel, $simplifiedPixels[$w - $distance][$h + $radius])
                || !matchPixels($pixel, $simplifiedPixels[$w + $distance][$h + $radius])
                || !matchPixels($pixel, $simplifiedPixels[$w - $distance][$h - $radius])
                || !matchPixels($pixel, $simplifiedPixels[$w + $distance][$h - $radius])
            ) {
                return $radius - 1;
            }
        }

        // Recurse
        return getRadius($w, $h, $pixel, $width, $height, $topSquares, $simplifiedPixels, $radius, $maximumRadius);
    }
        
    function matchPixels($pixel, $checkPixel) {
        return $pixel['r'] == $checkPixel['r'] && $pixel['g'] == $checkPixel['g'] && $pixel['b'] == $checkPixel['b'];
    }


















    function p($array, $name = '')
    {
        echo "{$name}<pre>" . print_r($array, true) . '</pre>';
    }

    if ($debug) {
        echo 'Time: ' . (time() - $time) . ' seconds';
    }
