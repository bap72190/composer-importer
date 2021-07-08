<?php

namespace ae\ComposerImporter\Composer;

use Composer\Semver\Semver;
use Composer\Util\ProcessExecutor;
use DrupalFinder\DrupalFinder;
use ae\ComposerImporter\Utility\ComposerJsonManipulator;
use ae\ComposerImporter\Utility\DrupalInspector;
use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;
use Symfony\Component\Filesystem\Filesystem;

class ImporterDrupalCommand extends BaseCommand
{

	/** @var InputInterface */
	protected $input;
	protected $baseDir;
	protected $composerConverterDir;
	protected $rootComposerJsonPath;
	protected $drupalRoot;
	protected $drupalRootRelative;
	protected $drupalCoreVersion;
	/** @var Filesystem */
	protected $fs;

	public function configure()
	{
		$this->setName('composer-importer');
		$this->setAliases(['ci']);
		$this->setDescription("Import/update composer file with contrib modules from an outdated site.");
		$this->addOption('composer-root', null, InputOption::VALUE_REQUIRED, 'The relative path to the directory that should contain composer.json.');
		$this->addOption('drupal-root', null, InputOption::VALUE_REQUIRED, 'The relative path to the Drupal root directory.');
		$this->addOption('exact-versions', null, InputOption::VALUE_NONE, 'Use exact version constraints rather than the recommended caret operator.');
		$this->addOption('no-update', null, InputOption::VALUE_NONE, 'Prevent "composer update" being run after file generation.');
		$this->addUsage('--composer-root=. --drupal-root=./docroot');
		$this->addUsage('--composer-root=. --drupal-root=./web');
		$this->addUsage('--composer-root=. --drupal-root=.');
		$this->addUsage('--exact-versions --no-update');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return int
	 * @throws Exception
	 */
	public function execute(InputInterface $input, OutputInterface $output)
	{
		$this->input = $input;
		$this->fs = new Filesystem();
		$this->setDirectories($input);
		$this->drupalCoreVersion = $this->determineDrupalCoreVersion();
		$this->addRequirementsToComposerJson();

		$exit_code = 0;
		if (!$input->getOption('no-update')) {
			$this->getIO()->write("Executing <comment>composer update</comment>...");
			$exit_code = $this->executeComposerUpdate();
		} else {
			$this->getIO()->write("Execute <comment>composer update</comment> to install dependencies.");
		}

		if (!$exit_code) {
			$this->printPostScript();
		}

		return $exit_code;
	}

	/**
	 * @return mixed
	 */
	public function getBaseDir()
	{
		return $this->baseDir;
	}

	/**
	 * @param mixed $baseDir
	 */
	public function setBaseDir($baseDir)
	{
		$this->baseDir = $baseDir;
	}

	protected function loadRootComposerJson()
	{
		return json_decode(file_get_contents($this->rootComposerJsonPath));
	}

	protected function addRequirementsToComposerJson()
	{
		$root_composer_json = $this->loadRootComposerJson();
		$projects = $this->findContribProjects($root_composer_json);
		$this->requireContribProjects($root_composer_json, $projects);
		$this->addPatches($projects, $root_composer_json);

		ComposerJsonManipulator::writeObjectToJsonFile(
			$root_composer_json,
			$this->rootComposerJsonPath
		);
	}

	/**
	 * @return mixed|string
	 * @throws Exception
	 */
	protected function determineDrupalCoreVersion()
	{
		if (file_exists($this->drupalRoot . "/core/lib/Drupal.php")) {
			$bootstrap =  file_get_contents($this->drupalRoot . "/core/lib/Drupal.php");
			$core_version = DrupalInspector::determineDrupalCoreVersionFromDrupalPhp($bootstrap);

			if (!Semver::satisfiedBy([$core_version], "*")) {
				throw new Exception("Drupal core version $core_version is invalid.");
			}

			return $core_version;
		}
		if (!isset($this->drupalCoreVersion)) {
			throw new Exception("Unable to determine Drupal core version.");
		}
		return false;
	}

	/**
	 * @param $root_composer_json
	 * @param $projects
	 */
	protected function requireContribProjects($root_composer_json, $projects)
	{
		foreach ($projects as $project_name => $project) {
			$package_name = "drupal/$project_name";
			$version_constraint = DrupalInspector::getVersionConstraint($project['version'], $this->input->getOption('exact-versions'));
			$root_composer_json->require->{$package_name} = $version_constraint;

			if ($version_constraint == "*") {
				$this->getIO()->write("<comment>Could not determine correct version for project $package_name. Added to requirements without constraint.</comment>");
			} else {
				$this->getIO()->write("<info>Added $package_name with constraint $version_constraint to requirements.</info>");
			}
		}
	}

	/**
	 * @param InputInterface $input
	 * @throws Exception
	 */
	protected function setDirectories(InputInterface $input)
	{
		$this->composerConverterDir = dirname(dirname(__DIR__));
		$drupalFinder = new DrupalFinder();
		$this->determineDrupalRoot($input, $drupalFinder);
		$this->determineComposerRoot($input, $drupalFinder);
		$this->drupalRootRelative = trim($this->fs->makePathRelative(
			$this->drupalRoot,
			$this->baseDir
		), '/');
		$this->rootComposerJsonPath = $this->baseDir . "/composer.json";
	}

	/**
	 * @return int
	 */
	protected function executeComposerUpdate()
	{
		$io = $this->getIO();
		$executor = new ProcessExecutor($io);
		$output_callback = function ($type, $buffer) use ($io) {
			$io->write($buffer, false);
		};
		return $executor->execute('composer update --no-interaction', $output_callback, $this->baseDir);
	}

	/**
	 * @param InputInterface $input
	 * @param DrupalFinder $drupalFinder
	 *
	 * @throws Exception
	 */
	protected function determineComposerRoot(
		InputInterface $input,
		DrupalFinder $drupalFinder
	) {
		if ($input->getOption('composer-root')) {
			if (!$this->fs->isAbsolutePath($input->getOption('composer-root'))) {
				$this->baseDir = getcwd() . "/" . $input->getOption('composer-root');
			} else {
				$this->baseDir = $input->getOption('composer-root');
			}
		} else {
			$this->baseDir = $drupalFinder->getComposerRoot();
			$confirm = $this->getIO()
				->askConfirmation("<question>Assuming that composer.json should be generated at $this->baseDir. Is this correct?</question> ");
			if (!$confirm) {
				throw new Exception("Please use --composer-root to specify the correct Composer root directory");
			}
		}
	}

	/**
	 * @param InputInterface $input
	 * @param DrupalFinder $drupalFinder
	 *
	 * @throws Exception
	 */
	protected function determineDrupalRoot(InputInterface $input, DrupalFinder $drupalFinder)
	{
		if (!$input->getOption('drupal-root')) {
			$common_drupal_root_subdirs = [
				'docroot',
				'web',
				'htdocs',
			];
			$root = getcwd();
			foreach ($common_drupal_root_subdirs as $candidate) {
				if (file_exists("$root/$candidate")) {
					$root = "$root/$candidate";
					break;
				}
			}
		} else {
			$root = $input->getOption('drupal-root');
		}

		if ($drupalFinder->locateRoot($root)) {
			$this->drupalRoot = $drupalFinder->getDrupalRoot();
			if (!$this->fs->isAbsolutePath($root)) {
				$this->drupalRoot = getcwd() . "/$root";
			}
		} else {
			throw new Exception("Unable to find Drupal root directory. Please change directories to a valid Drupal 8 application. Try specifying it with --drupal-root.");
		}
	}

	protected function printPostScript()
	{
		$this->getIO()->write("<info>Completed Import of Drupal Modules!</info>");
	}

	/**
	 * @param $root_composer_json
	 *
	 * @return array
	 */
	protected function findContribProjects($root_composer_json)
	{
		$modules_contrib = DrupalInspector::findContribProjects(
			$this->drupalRoot,
			"modules/contrib",
			$root_composer_json
		);
		$modules = DrupalInspector::findContribProjects(
			$this->drupalRoot,
			"modules",
			$root_composer_json
		);
		$themes = DrupalInspector::findContribProjects(
			$this->drupalRoot,
			"themes/contrib",
			$root_composer_json
		);
		$profiles = DrupalInspector::findContribProjects(
			$this->drupalRoot,
			"profiles/contrib",
			$root_composer_json
		);
		return array_merge($modules_contrib, $modules, $themes, $profiles);
	}

	/**
	 * @param $projects
	 * @param $root_composer_json
	 */
	protected function addPatches($projects, $root_composer_json)
	{
		$projects = DrupalInspector::findProjectPatches($projects);
		$patch_dir = $this->getBaseDir() . "/patches";
		$this->fs->mkdir($patch_dir);
		foreach ($projects as $project_name => $project) {
			if (array_key_exists('patches', $project)) {
				foreach ($project['patches'] as $patch) {
					$target_filename = $patch_dir . "/" . basename($patch);
					$this->fs->copy($patch, $target_filename);
					$relative_path = $this->fs->makePathRelative(
						$target_filename,
						$this->getBaseDir()
					);
					$relative_path = rtrim($relative_path, '/');
					$root_composer_json->extra->patches["drupal/" . $project_name][$relative_path] = $relative_path;
				}
			}
		}
	}
}
