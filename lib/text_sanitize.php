<?php
declare(strict_types=1);

/**
 * Sanitize Open Lines outbound text for Grey/Telegram: strip Bitrix BBCode and operator prefix.
 * Used only for Open Lines handler; Deal send path is unchanged.
 */

/**
 * Strip Bitrix BBCode and remove leading operator prefix (e.g. "user@domain: " or "Name: ").
 * Returns plain text suitable for Telegram.
 */
function sanitize_openlines_text(string $text): string {
  if ($text === '') return '';

  // 1) Convert [br] to newline
  $text = preg_replace('#\[br\]#i', "\n", $text);

  // 2) Strip BBCode (so "[b]bitrix24@edna.ru:[/b] [br]bbbbbb" becomes "bitrix24@edna.ru: bbbbbb")
  $text = preg_replace('#\[/?(?:b|i|u|s)\]#i', '', $text);
  $text = preg_replace('#\[/?(?:code|quote)\]#i', '', $text);
  $text = preg_replace('#\[url\s*=[^\]]*\]([^\[]*)\[/url\]#i', '$1', $text);
  $text = preg_replace('#\[url\]([^\[]*)\[/url\]#i', '$1', $text);
  $text = preg_replace('#\[(?:b|i|u|s|code|quote)\]#i', '', $text);
  $text = preg_replace('#\[/(?:b|i|u|s|code|quote)\]#i', '', $text);
  $text = preg_replace('#\[/?[a-z]+(?:=[^\]]*)?\]#i', '', $text);

  // 3) Remove leading operator prefix: "something@domain:" or "Name:" at start (Bitrix adds operator email/name)
  $text = preg_replace('#^\s*([^\s@]+@[^\s:]+|[^\s\[\]:]+):\s*#u', '', $text);

  // 4) Collapse multiple newlines/spaces and trim
  $text = preg_replace('/[ \t]+/', ' ', $text);
  $text = preg_replace("/\n{3,}/", "\n\n", $text);
  return trim($text);
}
