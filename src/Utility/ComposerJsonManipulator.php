<?php

namespace ae\ComposerImporter\Utility;

class ComposerJsonManipulator
{
	public static function writeObjectToJsonFile($object, $filename)
	{
		file_put_contents($filename, json_encode($object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}
}
