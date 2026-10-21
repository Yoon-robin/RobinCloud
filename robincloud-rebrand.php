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

// (?<!\|) and (?!\)\s*-) keep regex alternations like (?:ownCloud|Nextcloud)-iOS intact
const WORD = '/(?<!\|)\bNextcloud\b(?![-\/])(?!\)\s*-)(?! GmbH)(?! contributors)/';

function rebrand(string $text): string {
	return preg_replace(WORD, 'RobinCloud', $text);
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
		if (strpos($old, 'Nextcloud') === false) {
			continue;
		}
		// minified bundles keep their licence notices on the same line as the
		// code, so the word rule's own GmbH/contributors exceptions do the job
		$new = rebrand($old);
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
