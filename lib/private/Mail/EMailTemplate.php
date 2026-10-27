<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OC\Mail;

use OCP\Defaults;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;

/**
 * Class EMailTemplate
 *
 * addBodyText and addBodyButtonGroup automatically opens the body
 * addFooter, renderHtml, renderText automatically closes the body and the HTML if opened
 *
 * @package OC\Mail
 */
class EMailTemplate implements IEMailTemplate {
	protected string $subject = '';
	protected string $htmlBody = '';
	protected string $plainBody = '';
	/** indicated if the header is added */
	protected bool $headerAdded = false;
	/** indicated if the body is already opened */
	protected bool $bodyOpened = false;
	/** indicated if there is a list open in the body */
	protected bool $bodyListOpened = false;
	/** indicated if the footer is added */
	protected bool $footerAdded = false;
	/** @var array<array{name: string, content: string, mimeType: string}> images to embed inline, referenced via cid: */
	protected array $inlineImages = [];

	/*
	 * RobinCloud mail layout (2026-10-27). Nextcloud's version was a bare white
	 * page: a 175px dark pill around the logo, a 24px light title and centred
	 * grey lines, the verification code a plain "code: x" sentence. Now one
	 * card in the zinc palette of the web pages: a dark band with the logo,
	 * left-aligned text, buttons the card's width, the sign-up code in its own
	 * box, the footer under the card. Tables and inline styles only (Gmail
	 * strips <style> in many places); 480px wide, 100% on a phone.
	 *
	 * The card is opened in $head, so every row below is a <tr> of it, and it
	 * is closed by addFooter() or, without one, by renderHtml()/renderText().
	 */

	/** font stack: no web fonts in mail, so the systems' own Korean faces */
	protected const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI','Apple SD Gothic Neo','Malgun Gothic','Noto Sans KR',Roboto,'Helvetica Neue',Arial,sans-serif";
	protected const MONO = "ui-monospace,SFMono-Regular,Menlo,Consolas,'Liberation Mono',monospace";

	/** text for the inbox preview line, filled in at renderHtml() */
	protected string $preheader = '';
	/** sign-up mail: lines that read as fine print, shown under the button */
	protected array $notes = [];
	protected bool $cardClosed = false;
	/** sign-up mail: how many body lines have arrived so far */
	protected int $signUpLines = 0;

	protected string $head = <<<EOF
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" style="-webkit-font-smoothing:antialiased;background:#f4f4f5">
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="color-scheme" content="light">
	<meta name="supported-color-schemes" content="light">
	<title></title>
	<style type="text/css">@media only screen and (max-width:520px){.rc-outer{padding:16px 8px!important}.rc-pad{padding-left:22px!important;padding-right:22px!important}.rc-code{font-size:22px!important;letter-spacing:3px!important}}</style>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%">
	<div style="display:none!important;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all">%%PREHEADER%%&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;</div>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f5;border-collapse:collapse">
		<tr>
			<td class="rc-outer" align="center" style="padding:40px 16px">
				<table role="presentation" width="480" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:480px;border-collapse:separate;background:#ffffff;border:1px solid #e4e4e7;border-radius:12px">
				<tbody>

EOF;

	protected string $cardEnd = <<<EOF
				</tbody>
				</table>

EOF;

	protected string $tail = <<<EOF
			</td>
		</tr>
	</table>
</body>
</html>

EOF;

	/** %1\$s background, %2\$s logo src, %3\$s alt, %4\$s size attributes */
	protected string $header = <<<EOF
					<tr>
						<td class="rc-pad" style="background:%1\$s;border-radius:11px 11px 0 0;padding:20px 32px">
							<img src="%2\$s" alt="%3\$s"%4\$s style="display:block;border:0;outline:0;height:26px;width:auto;max-width:180px">
						</td>
					</tr>

EOF;

	protected string $heading = <<<EOF
					<tr>
						<td class="rc-pad" style="padding:30px 32px 6px;font-family:%1\$s;font-size:20px;line-height:1.35;font-weight:600;letter-spacing:-0.3px;color:#09090b;word-break:keep-all">%2\$s</td>
					</tr>

EOF;

	protected string $bodyBegin = '';

	protected string $bodyText = <<<EOF
					<tr>
						<td class="rc-pad" style="padding:6px 32px;font-family:%1\$s;font-size:14px;line-height:1.7;color:#52525b;word-break:keep-all">%2\$s</td>
					</tr>

EOF;

	/** %1\$s font, %2\$s label, %3\$s mono font, %4\$s code */
	protected string $codeBox = <<<EOF
					<tr>
						<td class="rc-pad" style="padding:18px 32px 6px">
							<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate">
								<tr>
									<td align="center" style="background:#f4f4f5;border:1px solid #e4e4e7;border-radius:10px;padding:18px 12px 20px">
										<div style="font-family:%1\$s;font-size:12px;line-height:1.4;color:#71717a;padding-bottom:8px">%2\$s</div>
										<div class="rc-code" style="font-family:%3\$s;font-size:26px;line-height:1.2;font-weight:700;letter-spacing:4px;color:#09090b;-webkit-user-select:all;user-select:all">%4\$s</div>
									</td>
								</tr>
							</table>
						</td>
					</tr>

EOF;

	protected string $listBegin = <<<EOF
					<tr>
						<td class="rc-pad" style="padding:6px 32px">
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse">

EOF;

	/** %1\$s font, %2\$s icon, %3\$s text */
	protected string $listItem = <<<EOF
								<tr>
									<td width="28" valign="top" style="padding:7px 0;font-family:%1\$s;font-size:14px;line-height:1.6;color:#a1a1aa">%2\$s</td>
									<td valign="top" style="padding:7px 0;font-family:%1\$s;font-size:14px;line-height:1.6;color:#3f3f46;word-break:keep-all">%3\$s</td>
								</tr>

EOF;

	protected string $listEnd = <<<EOF
							</table>
						</td>
					</tr>

EOF;

	/** %1\$s background, %2\$s url, %3\$s font, %4\$s text colour, %5\$s text */
	protected string $button = <<<EOF
					<tr>
						<td class="rc-pad" style="padding:20px 32px 6px">
							<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate">
								<tr>
									<td align="center" bgcolor="%1\$s" style="background:%1\$s;border-radius:8px">
										<a href="%2\$s" target="_blank" style="display:block;padding:12px 18px;font-family:%3\$s;font-size:14px;line-height:1.4;font-weight:600;color:%4\$s;text-decoration:none;border-radius:8px">%5\$s</a>
									</td>
								</tr>
							</table>
						</td>
					</tr>

EOF;

	/**
	 * %1\$s primary background, %2\$s left url, %3\$s font, %4\$s primary text
	 * colour, %5\$s left text, %6\$s right url, %7\$s right text
	 */
	protected string $buttonGroup = <<<EOF
					<tr>
						<td class="rc-pad" style="padding:20px 32px 6px">
							<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse">
								<tr>
									<td width="50%%" valign="top" style="padding-right:5px">
										<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate">
											<tr>
												<td align="center" bgcolor="%1\$s" style="background:%1\$s;border:1px solid %1\$s;border-radius:8px">
													<a href="%2\$s" target="_blank" style="display:block;padding:11px 14px;font-family:%3\$s;font-size:14px;line-height:1.4;font-weight:600;color:%4\$s;text-decoration:none;border-radius:8px">%5\$s</a>
												</td>
											</tr>
										</table>
									</td>
									<td width="50%%" valign="top" style="padding-left:5px">
										<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate">
											<tr>
												<td align="center" style="background:#ffffff;border:1px solid #e4e4e7;border-radius:8px">
													<a href="%6\$s" target="_blank" style="display:block;padding:11px 14px;font-family:%3\$s;font-size:14px;line-height:1.4;font-weight:600;color:#18181b;text-decoration:none;border-radius:8px">%7\$s</a>
												</td>
											</tr>
										</table>
									</td>
								</tr>
							</table>
						</td>
					</tr>

EOF;

	/** fine print under the button (sign-up mail) */
	protected string $note = <<<EOF
					<tr>
						<td class="rc-pad" style="padding:14px 32px 0;font-family:%1\$s;font-size:12.5px;line-height:1.65;color:#a1a1aa;word-break:keep-all">%2\$s</td>
					</tr>

EOF;

	protected string $bodyEnd = <<<EOF
					<tr><td style="height:28px;font-size:0;line-height:0">&nbsp;</td></tr>

EOF;

	protected string $footer = <<<EOF
				<table role="presentation" width="480" cellpadding="0" cellspacing="0" border="0" style="width:100%%;max-width:480px;border-collapse:collapse">
					<tr>
						<td align="center" style="padding:18px 16px 0;font-family:%1\$s;font-size:12px;line-height:1.7;color:#a1a1aa;word-break:keep-all">%2\$s</td>
					</tr>
				</table>

EOF;


	public function __construct(
		protected Defaults $themingDefaults,
		protected IURLGenerator $urlGenerator,
		protected IFactory $l10nFactory,
		protected ?int $logoWidth,
		protected ?int $logoHeight,
		protected string $emailId,
		protected array $data,
	) {
		$this->htmlBody .= $this->head;
	}

	/**
	 * Sets the subject of the email
	 */
	#[\Override]
	public function setSubject(string $subject): void {
		$this->subject = $subject;
	}

	/**
	 * Adds a header to the email: the logo on a dark band across the top of
	 * the card (the logo is drawn white, for the dark login page).
	 */
	#[\Override]
	public function addHeader(): void {
		if ($this->headerAdded) {
			return;
		}
		$this->headerAdded = true;

		// Outlook ignores CSS height on images: give it the attributes, scaled
		// to the 26px the band shows it at.
		$logoSizeDimensions = '';
		if ($this->logoWidth && $this->logoHeight) {
			$height = 26;
			$width = (int)round($this->logoWidth * $height / $this->logoHeight);
			$logoSizeDimensions = ' width="' . $width . '" height="' . $height . '"';
		}

		$logoImage = $this->themingDefaults->getLogoImage();
		if ($logoImage !== null) {
			// Embed the logo directly in the message instead of linking to it, so mail
			// clients don't have to fetch it from the internet (some (e.g. gmail) block that).
			$logoSrc = 'cid:logo';
			$this->inlineImages[] = ['name' => 'logo'] + $logoImage;
		} else {
			$logoSrc = $this->urlGenerator->getAbsoluteURL($this->themingDefaults->getLogo(false));
		}
		$this->htmlBody .= vsprintf($this->header, ['#09090b', $logoSrc, htmlspecialchars($this->themingDefaults->getName()), $logoSizeDimensions]);
	}

	/**
	 * @return array<array{name: string, content: string, mimeType: string}>
	 */
	public function getInlineImages(): array {
		return $this->inlineImages;
	}

	/**
	 * Adds a heading to the email
	 *
	 * @param string|bool $plainTitle Title that is used in the plain text email
	 *                                if empty the $title is used, if false none will be used
	 */
	#[\Override]
	public function addHeading(string $title, $plainTitle = ''): void {
		if ($this->footerAdded) {
			return;
		}
		// The sign-up mail's heading is a bare "Registration": the subject
		// ("<name> sign-up code") says what the mail is for.
		if ($this->emailId === 'registration_verify' && $this->subject !== '') {
			$title = $this->subject;
			if ($plainTitle !== false) {
				$plainTitle = $this->subject;
			}
		}
		if ($plainTitle === '') {
			$plainTitle = $title;
		}

		$this->htmlBody .= vsprintf($this->heading, [self::FONT, htmlspecialchars($title)]);
		if ($plainTitle !== false) {
			$this->plainBody .= $plainTitle . PHP_EOL . PHP_EOL;
		}
	}

	/**
	 * open the HTML body when it is not already
	 */
	protected function ensureBodyIsOpened(): void {
		if ($this->bodyOpened) {
			return;
		}

		$this->htmlBody .= $this->bodyBegin;
		$this->bodyOpened = true;
	}

	/**
	 * Adds a paragraph to the body of the email
	 *
	 * @param string $text Note: When $plainText falls back to this, HTML is automatically escaped in the HTML email
	 * @param string|bool $plainText Text that is used in the plain text email
	 *                               if empty the $text is used, if false none will be used
	 */
	#[\Override]
	public function addBodyText(string $text, $plainText = ''): void {
		if ($this->footerAdded) {
			return;
		}
		if ($plainText === '') {
			$plainText = $text;
			$text = htmlspecialchars($text);
		}

		$this->ensureBodyListClosed();
		$this->ensureBodyIsOpened();

		if ($this->emailId === 'registration_verify' && $this->addSignUpLine($text, $plainText)) {
			return;
		}

		$this->htmlBody .= vsprintf($this->bodyText, [self::FONT, $text]);
		if ($plainText !== false) {
			$this->plainBody .= $plainText . PHP_EOL . PHP_EOL;
		}
	}

	/**
	 * The registration app's verification mail (MailService::sendTokenByMail)
	 * sends: the lead line with "Click the button below to continue." glued
	 * on, the admin's hint, "Verification code: <token>", the button. Here the
	 * code goes in a box of its own, the glued-on sentence goes (the Korean
	 * lead already names the button), and the hint becomes fine print under
	 * the button. Anything that does not fit this shape renders as usual.
	 *
	 * @param string|bool $plainText
	 */
	protected function addSignUpLine(string $text, $plainText): bool {
		$token = (string)($this->data['token'] ?? '');
		$plain = is_string($plainText) ? $plainText : html_entity_decode(strip_tags($text));

		if ($token !== '' && str_contains($plain, $token)) {
			$label = trim(str_replace($token, '', $plain), " \t\u{00a0}:：");
			$this->htmlBody .= vsprintf($this->codeBox, [self::FONT, htmlspecialchars($label), self::MONO, htmlspecialchars($token)]);
			$this->preheader = $plain;
			if ($plainText !== false) {
				$this->plainBody .= $plain . PHP_EOL . PHP_EOL;
			}
			$this->signUpLines++;
			return true;
		}

		if ($this->signUpLines === 0) {
			$suffix = ' ' . htmlspecialchars($this->l10nFactory->get('registration')->t('Click the button below to continue.'));
			if (str_ends_with($text, $suffix)) {
				$text = substr($text, 0, -strlen($suffix));
			}
			$this->htmlBody .= vsprintf($this->bodyText, [self::FONT, $text]);
			if ($plainText !== false) {
				$this->plainBody .= $plainText . PHP_EOL . PHP_EOL;
			}
			$this->signUpLines++;
			return true;
		}

		$this->notes[] = $text;
		if ($plainText !== false) {
			$this->plainBody .= $plainText . PHP_EOL . PHP_EOL;
		}
		$this->signUpLines++;
		return true;
	}

	/**
	 * Adds a list item to the body of the email
	 *
	 * @param string $text Note: When $plainText falls back to this, HTML is automatically escaped in the HTML email
	 * @param string $metaInfo Note: When $plainMetaInfo falls back to this, HTML is automatically escaped in the HTML email
	 * @param string $icon Absolute path, must be 16*16 pixels
	 * @param string|bool $plainText Text that is used in the plain text email
	 *                               if empty or true the $text is used, if false none will be used
	 * @param string|bool $plainMetaInfo Meta info that is used in the plain text email
	 *                                   if empty or true the $metaInfo is used, if false none will be used
	 * @param integer plainIndent If > 0, Indent plainText by this amount.
	 * @since 12.0.0
	 */
	#[\Override]
	public function addBodyListItem(
		string $text,
		string $metaInfo = '',
		string $icon = '',
		$plainText = '',
		$plainMetaInfo = '',
		$plainIndent = 0,
	): void {
		$this->ensureBodyListOpened();

		if ($plainText === '' || $plainText === true) {
			$plainText = $text;
			$text = htmlspecialchars($text);
			$text = str_replace("\n", '<br/>', $text); // convert newlines to HTML breaks
		}
		if ($plainMetaInfo === '' || $plainMetaInfo === true) {
			$plainMetaInfo = $metaInfo;
			$metaInfo = htmlspecialchars($metaInfo);
		}

		$htmlText = $text;
		if ($metaInfo) {
			$htmlText = '<span style="color:#71717a;font-size:12.5px">' . $metaInfo . '</span><br>' . $htmlText;
		}
		if ($icon !== '') {
			$icon = '<img src="' . htmlspecialchars($icon) . '" alt="&bull;" width="16" height="16" style="display:block;border:0;margin-top:3px">';
		} else {
			$icon = '&bull;';
		}
		$this->htmlBody .= vsprintf($this->listItem, [self::FONT, $icon, $htmlText]);
		if ($plainText !== false) {
			if ($plainIndent === 0) {
				/*
				 * If plainIndent is 0 show plain text mail formatted with
				 * plainMetaInfo in brackets.
				 */
				$this->plainBody .= '  * ' . $plainText;
				if ($plainMetaInfo !== false) {
					$this->plainBody .= ' (' . $plainMetaInfo . ')';
				}
				$this->plainBody .= PHP_EOL;
			} else {
				/*
				 * If plainIndent is set show plain text mail formatted with
				 * plainMetaInfo prepended with an indent.
				 */
				$label = ($plainMetaInfo !== false)? $plainMetaInfo : '';
				$this->plainBody .= sprintf("%{$plainIndent}s %s\n",
					$label,
					str_replace("\n", "\n" . str_repeat(' ', $plainIndent + 1), $plainText));
			}
		}
	}

	protected function ensureBodyListOpened(): void {
		if ($this->bodyListOpened) {
			return;
		}

		$this->ensureBodyIsOpened();
		$this->bodyListOpened = true;
		$this->htmlBody .= $this->listBegin;
	}

	protected function ensureBodyListClosed(): void {
		if (!$this->bodyListOpened) {
			return;
		}

		$this->bodyListOpened = false;
		$this->htmlBody .= $this->listEnd;
	}

	/**
	 * Adds a button group of two buttons to the body of the email
	 *
	 * @param string $textLeft Text of left button; Note: When $plainTextLeft falls back to this, HTML is automatically escaped in the HTML email
	 * @param string $urlLeft URL of left button
	 * @param string $textRight Text of right button; Note: When $plainTextRight falls back to this, HTML is automatically escaped in the HTML email
	 * @param string $urlRight URL of right button
	 * @param string $plainTextLeft Text of left button that is used in the plain text version - if unset the $textLeft is used
	 * @param string $plainTextRight Text of right button that is used in the plain text version - if unset the $textRight is used
	 */
	#[\Override]
	public function addBodyButtonGroup(
		string $textLeft,
		string $urlLeft,
		string $textRight,
		string $urlRight,
		string $plainTextLeft = '',
		string $plainTextRight = '',
	): void {
		if ($this->footerAdded) {
			return;
		}
		if ($plainTextLeft === '') {
			$plainTextLeft = $textLeft;
			$textLeft = htmlspecialchars($textLeft);
		}

		if ($plainTextRight === '') {
			$plainTextRight = $textRight;
			$textRight = htmlspecialchars($textRight);
		}

		$this->ensureBodyIsOpened();
		$this->ensureBodyListClosed();

		$color = $this->themingDefaults->getDefaultColorPrimary();
		$textColor = $this->themingDefaults->getDefaultTextColorPrimary();

		$this->htmlBody .= vsprintf($this->buttonGroup, [$color, $urlLeft, self::FONT, $textColor, $textLeft, $urlRight, $textRight]);
		$this->plainBody .= PHP_EOL . $plainTextLeft . ': ' . $urlLeft . PHP_EOL;
		$this->plainBody .= $plainTextRight . ': ' . $urlRight . PHP_EOL . PHP_EOL;
	}

	/**
	 * Adds a button to the body of the email
	 *
	 * @param string $text Text of button; Note: When $plainText falls back to this, HTML is automatically escaped in the HTML email
	 * @param string $url URL of button
	 * @param string|false $plainText Text of button in plain text version
	 *                                if empty the $text is used, if false none will be used
	 *
	 * @since 12.0.0
	 */
	#[\Override]
	public function addBodyButton(string $text, string $url, $plainText = ''): void {
		if ($this->footerAdded) {
			return;
		}

		$this->ensureBodyIsOpened();
		$this->ensureBodyListClosed();

		if ($plainText === '') {
			$plainText = $text;
			$text = htmlspecialchars($text);
		}

		$color = $this->themingDefaults->getDefaultColorPrimary();
		$textColor = $this->themingDefaults->getDefaultTextColorPrimary();
		$this->htmlBody .= vsprintf($this->button, [$color, $url, self::FONT, $textColor, $text]);

		if ($plainText !== false) {
			$this->plainBody .= $plainText . ': ';
		}

		$this->plainBody .= $url . PHP_EOL;
	}

	/**
	 * Close the HTML body when it is open
	 */
	protected function ensureBodyIsClosed(): void {
		if (!$this->bodyOpened) {
			return;
		}

		$this->ensureBodyListClosed();

		if ($this->notes !== []) {
			$this->htmlBody .= vsprintf($this->note, [self::FONT, implode('<br>', $this->notes)]);
			$this->notes = [];
		}

		$this->htmlBody .= $this->bodyEnd;
		$this->bodyOpened = false;
	}

	/** End the card: a mail with only a heading still gets its bottom padding. */
	protected function closeCard(): void {
		if ($this->cardClosed) {
			return;
		}
		$this->cardClosed = true;
		if ($this->bodyOpened) {
			$this->ensureBodyIsClosed();
		} else {
			$this->htmlBody .= $this->bodyEnd;
		}
		$this->htmlBody .= $this->cardEnd;
	}

	/**
	 * Adds a logo and a text to the footer. <br> in the text will be replaced by new lines in the plain text email
	 *
	 * @param string $text If the text is empty the default "Name - Slogan<br>This is an automatically sent email" will be used
	 * @param string $lang Optional language to set the default footer in
	 */
	#[\Override]
	public function addFooter(string $text = '', ?string $lang = null): void {
		if ($text === '') {
			$l10n = $this->l10nFactory->get('lib', $lang);
			$slogan = $this->themingDefaults->getSlogan($lang);
			if ($slogan !== '') {
				$slogan = ' - ' . $slogan;
			}
			$text = $this->themingDefaults->getName() . $slogan . '<br>' . $l10n->t('This is an automatically sent email, please do not reply.');
		}

		if ($this->footerAdded) {
			return;
		}
		$this->footerAdded = true;

		$this->closeCard();

		$this->htmlBody .= vsprintf($this->footer, [self::FONT, $text]);
		$this->htmlBody .= $this->tail;
		$this->plainBody .= PHP_EOL . '-- ' . PHP_EOL;
		$this->plainBody .= str_replace('<br>', PHP_EOL, $text);
	}

	/**
	 * Returns the rendered email subject as string
	 */
	#[\Override]
	public function renderSubject(): string {
		return $this->subject;
	}

	/**
	 * Returns the rendered HTML email as string
	 */
	#[\Override]
	public function renderHtml(): string {
		if (!$this->footerAdded) {
			$this->footerAdded = true;
			$this->closeCard();
			$this->htmlBody .= $this->tail;
		}
		$marker = '%%PREHEADER%%';
		$at = strpos($this->htmlBody, $marker);
		if ($at === false) {
			return $this->htmlBody;
		}
		return substr_replace($this->htmlBody, htmlspecialchars($this->preheader), $at, strlen($marker));
	}

	/**
	 * Returns the rendered plain text email as string
	 */
	#[\Override]
	public function renderText(): string {
		if (!$this->footerAdded) {
			$this->footerAdded = true;
			$this->closeCard();
			$this->htmlBody .= $this->tail;
		}
		return $this->plainBody;
	}
}
