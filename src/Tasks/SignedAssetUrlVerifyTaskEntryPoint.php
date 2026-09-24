<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tasks;

use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/*
 * Version-specific entry point for SignedAssetUrlVerifyTask.
 *
 * WHY THIS FILE DECLARES THE SAME TRAIT TWICE
 *
 * BuildTask changed shape between Silverstripe 5 and 6 in ways one class body cannot satisfy:
 *  - SS5: `abstract public function run($request)`, untyped `protected $title` / `$description`.
 *  - SS6: `run(InputInterface, PolyOutput): int` is concrete and `execute()` is the abstract hook;
 *    `protected string $title` and `protected static string $description` are typed, so a child
 *    redeclaring them untyped (or typed, on SS5) is a fatal "must be compatible" error.
 *
 * Two task classes with a file-level `return` guard each does NOT work for a BuildTask: the class
 * manifest still lists the guarded class as a BuildTask descendant, and the task runner reflects
 * on every descendant, which throws for a class that was never declared. So the task class is
 * declared once, and pulls its version-specific METHODS from this trait. The trait is declared
 * conditionally, which PHP allows; the class manifest does not see it (its visitor does not
 * descend into `if` blocks), and does not need to: Composer's PSR-4 autoloader resolves it by
 * file name. Same pattern as restruct/silverstripe-svg-images' ClearSVGVariantsTask.
 *
 * PolyOutput exists only on Silverstripe 6 (framework 6 `src/PolyExecution/`), so its presence is
 * the version switch. The `use` imports above are inert on SS5: an import never autoloads.
 */
if (class_exists(PolyOutput::class)) {
    /**
     * Silverstripe 6: sake command `tasks:SignedAssetUrlVerifyTask`, or dev/tasks/SignedAssetUrlVerifyTask.
     */
    trait SignedAssetUrlVerifyTaskEntryPoint
    {
        /**
         * SS6 declares getDescription() static (PolyCommand::getDescription()). Overridden here
         * rather than via a `$description` property: PHP refuses a trait property whose default
         * differs from the parent class's, and on SS5 the parent property is not even static.
         */
        public static function getDescription(): string
        {
            return static::DESCRIPTION;
        }

        protected function execute(InputInterface $input, PolyOutput $output): int
        {
            $this->verify(function (string $message, bool $newline = true) use ($output): void {
                // PolyOutput renders line endings for the terminal and for HTML itself.
                // OUTPUT_RAW: messages are plain text and carry no console formatting tags, so
                // nothing in them (eg a path in angle brackets) is mistaken for one.
                $output->write($message, $newline, PolyOutput::OUTPUT_RAW);
            });

            return Command::SUCCESS;
        }
    }
} else {
    /**
     * Silverstripe 5: dev/tasks/SignedAssetUrlVerifyTask (browser or `sake dev/tasks/...`).
     */
    trait SignedAssetUrlVerifyTaskEntryPoint
    {
        /**
         * SS5 declares getDescription() as an instance method (and deprecates reading the
         * `$description` property through it); see the SS6 variant for why this is a method.
         *
         * @return string
         */
        public function getDescription()
        {
            return static::DESCRIPTION;
        }

        /**
         * @param \SilverStripe\Control\HTTPRequest $request
         * @return void
         */
        public function run($request)
        {
            $this->verify(function (string $message, bool $newline = true): void {
                $this->output($message, $newline);
            });
        }
    }
}
