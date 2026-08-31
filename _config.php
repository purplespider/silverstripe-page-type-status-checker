<?php

/**
 * Silverstripe CMS 5 only recognises a directory as a module if it contains
 * _config.php or a _config/ directory (see ManifestFileFinder::isDirectoryModule).
 * Without one, this module's classes never enter the class manifest and the
 * BuildTask does not appear under dev/tasks.
 *
 * CMS 6 does not have this requirement, which is why the main branch has no
 * equivalent file. Nothing needs configuring here; the file's presence is the point.
 */
