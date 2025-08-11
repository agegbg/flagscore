<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Filförteckning</title>

    <!-- Bootstrap CSS -->  
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/css/bootstrap.min.css"
          integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T"
          crossorigin="anonymous">

    <style>
        .file-link {
            font-size: 1em;
            display: block;
            margin-bottom: 5px;
            word-break: break-all;
        }
        .file-section {
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
<div class="container">
    <h2 class="mt-4">Filförteckning</h2>

    <?php
    $files = scandir(__DIR__);
    $html_files = [];
    $php_files = [];

    foreach ($files as $file) {
        if (is_file($file)) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if ($ext === 'html') {
                $html_files[] = $file;
            } elseif ($ext === 'php') {
                $php_files[] = $file;
            }
        }
    }

    sort($html_files);
    sort($php_files);

    function renderFileSection($title, $fileArray) {
        echo '<div class="file-section">';
        echo "<h5>$title</h5>";
        echo '<div class="row">';
        foreach ($fileArray as $index => $file) {
            echo '<div class="col-md-4 col-sm-6">';
            echo '<a href="' . $file . '" class="file-link" target="_blank">' . $file . '</a>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }

    renderFileSection("HTML-filer", $html_files);
    renderFileSection("PHP-filer", $php_files);
    ?>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.3.1.slim.min.js"
        integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo"
        crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.14.7/dist/umd/popper.min.js"
        integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1"
        crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.3.1/dist/js/bootstrap.min.js"
        integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIyL0Y4i1Qn6Phbdj6lZ4W0EeypZ6P0ZXn8u5JIs"
        crossorigin="anonymous"></script>
</body>
</html>
