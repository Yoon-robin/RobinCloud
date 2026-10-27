<?php
/**
 * RobinCloud: replaces the user-facing word "Nextcloud" with "RobinCloud"
 * across a Nextcloud tree. Runs at image build time over /usr/src/nextcloud,
 * so the apps that ship only in the release tarball are covered too.
 *
 *   php robincloud-rebrand.php /usr/src/nextcloud
 *
 * What changes, with one rule everywhere so translation keys keep matching
 * the strings the code looks up:
 *   - "Nextcloud" as a whole word
 * What does not:
 *   - "Nextcloud-…", "Nextcloud/…" and "|Nextcloud)-…": client and user-agent
 *     identifiers (Nextcloud-android, Nextcloud-iOS …) and the regexes that
 *     match them
 *   - "Nextcloud GmbH", "Nextcloud contributors": copyright notices (AGPL)
 *   - PHP outside translation calls ->t('…') / ->n('…', '…'): that is code
 *
 * Idempotent: running it twice changes nothing more.
 */

$root = rtrim($argv[1] ?? '', '/');
if ($root === '' || !is_dir($root)) {
	fwrite(STDERR, "usage: php robincloud-rebrand.php <nextcloud root>\n");
	exit(1);
}

// (?<!\|) and (?!\)\s*-) keep regex alternations like (?:ownCloud|Nextcloud)-iOS intact.
// The files are rewritten as source text, so a JSON/JS escape such as \n or
// \t right before the word leaves a letter there and \b never fires
// ("…!\n\nNextcloud Calendar"): after \n, \r or \t the word starts too.
const WORD = '/(?<!\|)(?:(?<=\\\\[nrt])|\b)Nextcloud\b(?![-\/])(?!\)\s*-)(?! GmbH)(?! contributors)/';

function rebrand(string $text): string {
	return preg_replace(WORD, 'RobinCloud', $text);
}

/*
 * Korean that JS libraries bake into the bundles: @nextcloud/vue ships its own
 * gettext tables as {l:"ko",t:{"msgid":{v:["번역"]},…}} inside every bundle
 * that uses it (NcPasswordField's "암호 표시" on the login page). Those never
 * see <app>/l10n/ko.json, so the overlay's "_bundle" section ({"msgid":
 * "번역"}) is rewritten in place, only inside the "ko" tables. Singular
 * entries only ({v:[one]}), which is all the library has for these.
 */
$bundleKo = [];
if (is_file(__DIR__ . '/robincloud-l10n/ko.json')) {
	$bundleKo = (json_decode(file_get_contents(__DIR__ . '/robincloud-l10n/ko.json'), true) ?: [])['_bundle'] ?? [];
}

function bundleKo(string $js, array $strings): string {
	$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
	$out = '';
	$pos = 0;
	while ($strings && ($start = strpos($js, '{l:"ko",t:{', $pos)) !== false) {
		// a table runs until the next locale's {l:"…"; anything between the
		// last locale and the next table is code, which never has the shape
		$end = strpos($js, '{l:"', $start + 1);
		$end = $end === false ? strlen($js) : $end;
		$table = substr($js, $start, $end - $start);
		foreach ($strings as $msgid => $ko) {
			$key = json_encode((string)$msgid, $flags);
			$entry = $key . ':{v:[' . json_encode($ko, $flags) . ']}';
			$table = preg_replace_callback('/' . preg_quote($key, '/') . ':\{v:\["(?:[^"\\\\]|\\\\.)*"\]\}/', fn () => $entry, $table);
		}
		$out .= substr($js, $pos, $start - $pos) . $table;
		$pos = $end;
	}
	return $out . substr($js, $pos);
}

$stats = ['l10n' => 0, 'js' => 0, 'php' => 0, 'info' => 0];

$it = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		fn ($f) => !in_array($f->getFilename(), ['.git', 'node_modules', 'tests', 'data', 'config', '3rdparty', 'vendor'], true)
	)
);

foreach ($it as $file) {
	$path = $file->getPathname();
	$name = $file->getFilename();
	$rel = substr($path, strlen($root));

	if (preg_match('#/l10n/[^/]+\.(js|json)$#', $rel)) {
		$kind = 'l10n';
		$new = rebrand($old = file_get_contents($path));
	} elseif (preg_match('#/(dist|js)/[^/]+\.m?js$#', $rel)) {
		$kind = 'js';
		$old = file_get_contents($path);
		$koTables = $bundleKo && strpos($old, '{l:"ko",t:{') !== false;
		if (strpos($old, 'Nextcloud') === false && !$koTables) {
			continue;
		}
		// minified bundles keep their licence notices on the same line as the
		// code, so the word rule's own GmbH/contributors exceptions do the job
		$new = rebrand($old);
		if ($koTables) {
			$new = bundleKo($new, $bundleKo);
		}
	} elseif (str_ends_with($name, '.php') && strpos($rel, '/l10n/') === false) {
		$kind = 'php';
		$old = file_get_contents($path);
		if (strpos($old, 'Nextcloud') === false || strpos($old, '->t(') === false && strpos($old, '->n(') === false) {
			continue;
		}
		// only the string literals passed to ->t( / ->n(
		$new = preg_replace_callback(
			'/->(?:t|n)\(\s*((?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")(?:\s*,\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"))?)/s',
			fn ($m) => str_replace($m[1], rebrand($m[1]), $m[0]),
			$old
		);
	} elseif ($name === 'info.xml' && str_ends_with($rel, '/appinfo/info.xml')) {
		$kind = 'info';
		$old = file_get_contents($path);
		$new = preg_replace_callback(
			'#<(name|summary|description)([^>]*)>(.*?)</\1>#s',
			fn ($m) => '<' . $m[1] . $m[2] . '>' . rebrand($m[3]) . '</' . $m[1] . '>',
			$old
		);
	} else {
		continue;
	}

	if ($new !== null && $new !== $old) {
		file_put_contents($path, $new);
		$stats[$kind]++;
	}
}

foreach ($stats as $kind => $n) {
	echo str_pad($kind, 6), " files rebranded: $n\n";
}

/*
 * Korean overlay: robincloud-l10n/ko.json next to this script holds
 * translations Nextcloud ships without ({"app": {"msgid": "번역"}}). They go
 * into <app>/l10n/ko.json, and ko.js is rebuilt from it, since the browser
 * reads the .js and PHP reads the .json. Works for a whole tree (apps/,
 * custom_apps/, core/) and for a custom_apps directory passed as the root.
 */
$overlayFile = __DIR__ . '/robincloud-l10n/ko.json';
if (is_file($overlayFile)) {
	$overlay = json_decode(file_get_contents($overlayFile), true) ?: [];
	$merged = 0;
	foreach ($overlay as $app => $strings) {
		if ($app[0] === '_' || !is_array($strings)) {
			continue;
		}
		// "core" and "lib" are not apps: core/ is told by its shipped.json, lib/
		// (server strings such as the mail footer) by lib/private next to l10n/
		$candidates = in_array($app, ['core', 'lib'], true) ? ["$root/$app/l10n"] : ["$root/apps/$app/l10n", "$root/custom_apps/$app/l10n", "$root/$app/l10n"];
		$marker = ['core' => '/shipped.json', 'lib' => '/private/Mail'][$app] ?? '/appinfo/info.xml';
		foreach ($candidates as $dir) {
			if (!is_dir(dirname($dir)) || !file_exists(dirname($dir) . $marker)) {
				continue;
			}
			@mkdir($dir);
			$json = is_file("$dir/ko.json") ? json_decode(file_get_contents("$dir/ko.json"), true) : null;
			$json = is_array($json) ? $json : ['translations' => [], 'pluralForm' => 'nplurals=1; plural=0;'];
			$before = $json['translations'];
			$json['translations'] = array_merge($json['translations'], $strings);
			if ($json['translations'] === $before && is_file("$dir/ko.js")) {
				continue;
			}
			$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
			file_put_contents("$dir/ko.json", json_encode($json, $flags) . "\n");
			file_put_contents("$dir/ko.js", "OC.L10N.register(\n    " . json_encode($app) . ",\n    "
				. json_encode((object)$json['translations'], $flags) . ",\n" . json_encode($json['pluralForm']) . ");\n");
			$merged++;
		}
	}
	echo "ko overlay merged into $merged app(s)\n";
}
