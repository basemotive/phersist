<?php

namespace Tests\Support;

/**
 * Directories for what the tests generate.
 *
 * @author Stefan Mensink <stefan@basemotive.nl>
 * @copyright Basemotive VOF - https://www.basemotive.nl/
 * // SPDX-License-Identifier: LGPL-2.1-or-later
 */
final class Files {
	private static ?string $tempBase = null;

	private static int $tempCount = 0;

	/**
	 * A new, empty directory in tests/.generated/<target>/tmp/. It's kept
	 * after the test for inspection, and emptied at the start of the next run.
	 */
	public static function tempDir() : string {
		if (self::$tempBase === null) {
			$base = Target::generatedDir().'/'.Target::current()->name.'/tmp';
			if (is_dir($base))
				self::removeDir($base);
			mkdir($base, 0777, true);
			self::$tempBase = realpath($base) ?: $base;
		}
		$dir = self::$tempBase.'/'.(++self::$tempCount);
		mkdir($dir);
		return $dir;
	}

	/**
	 * Removes a directory and everything in it.
	 */
	public static function removeDir(string $dir) : void {
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($items as $item) {
			/** @var \SplFileInfo $item */
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($dir);
	}
}
