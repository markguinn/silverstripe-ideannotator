<?php

/**
 * Class DataObjectAnnotator
 * Generates phpdoc annotations for database fields and orm relations
 * so IDE's with autocompletion and property inspection will recognize properties and relation methods.
 *
 * The annotations can be generated with dev/build with @see Annotatable
 * and from the @see DataObjectAnnotatorTask
 *
 * The generation is disabled by default.
 * It is advisable to only enable it in your local dev environment,
 * so the files won't change on a production server when you run dev/build
 */

class DataObjectAnnotator extends Object
{

    /**
     * This string marks the beginning of a generated annotations block
     */
    const STARTTAG = '============================================================== (generated)';

    /**
     * This string marks the end of a generated annotations block
     */
    const ENDTAG = '============================================================== (/generated)';

    /**
     * @config
     * Enable generation from @see Annotatable and @see DataObjectAnnotatorTask
     */
    private static $enabled = false;

    /**
     * @config
     * Enable modules that are allowed to have generated docblocks for DataObjects and DataExtensions
     */
    private static $enabled_modules = array('mysite');

    /**
     * @var array
     * Available properties to generate docblocks for.
     */
    protected static $propertyTypes = array(
        'Owner',
        'DB',
        'HasOne',
        'BelongsTo',
        'HasMany',
        'ManyMany',
        'BelongsManyMany',
        'Extensions',
    );

    /**
     * List of all objects, so we can find the extensions.
     * @var array
     */
    protected $objectList = array();

    /**
     * @var string
     * Overall string for dataset.
     */
    protected $resultString = '';

    /**
     * @param            $moduleName
     * @param bool|false $undo
     *
     * Generate docblock for all subclasses of DataObjects and DataExtenions
     * within a module.
     *
     * @return false || void
     */
    public function annotateModule($moduleName, $undo = false)
    {
        if (!$this->moduleIsAllowed($moduleName)) {
            return false;
        }

        $this->objectList = ClassInfo::subclassesFor('Object');
        $classNames = ClassInfo::subclassesFor('DataObject');
        foreach ($classNames as $className) {
            $this->annotateDataObject($className, $undo);
            $this->resultString = ''; // Reset the result after each class
        }

        $classNames = ClassInfo::subclassesFor('DataExtension');
        foreach ($classNames as $className) {
            $this->annotateDataObject($className, $undo);
            $this->resultString = '';
        }

        return null;
    }

    /**
     * @param            $className
     * @param bool|false $undo
     *
     * Generate docblock for a single subclass of DataObject or DataExtenions
     *
     * @return bool
     */
    public function annotateDataObject($className, $undo = false)
    {
        if (!$this->classNameIsAllowed($className)) {
            return false;
        }

        $filePath = $this->getClassFilePath($className);
        $this->objectList = ClassInfo::subclassesFor('Object');

        if (!$filePath) {
            return false;
        }

        if ($undo) {
            $this->removePHPDocBlock($filePath);
        } else {
            $original = file_get_contents($filePath);
            $annotated = $this->getFileContentWithAnnotations($original, $className);
            // nothing has changed, no need to write to the file
            if ($annotated && $annotated !== $original) {
                file_put_contents($filePath, $annotated);
            }
        }

        return null;
    }

    /**
     * Revert the file to its original state without the generated docblock from this module
     *
     * @param $className
     * @see removePHPDocBlock
     * @return bool
     */
    public function undoDataObject($className)
    {
        if (!$this->classNameIsAllowed($className)) {
            return false;
        }

        $filePath = $this->getClassFilePath($className);

        if (!$filePath) {
            return false;
        }

        $this->removePHPDocBlock($filePath);

        return null;
    }

    /**
     * Performs the actual file writing
     * @param $filePath
     */
    protected function removePHPDocBlock($filePath)
    {
        $original = file_get_contents($filePath);
        $reverted = $this->getFileContentWithoutAnnotations($original);
        // nothing has changed, no need to write to the file
        if ($reverted && $reverted !== $original) {
            file_put_contents($filePath, $reverted);
        }
    }

    /**
     * Check if a DataObject or DataExtension subclass is allowed by checking if the file
     * is in the $allowed_modules array
     * The permission is checked by matching the filePath and modulePath
     *
     * @param $className
     *
     * @return bool
     */
    protected function classNameIsAllowed($className)
    {
        if (is_subclass_of($className, 'DataObject') || is_subclass_of($className, 'DataExtension')) {

            $filePath = $this->getClassFilePath($className);
            $allowedModules = Config::inst()->get('DataObjectAnnotator', 'enabled_modules');

            foreach ($allowedModules as $moduleName) {
                $modulePath = BASE_PATH . DIRECTORY_SEPARATOR . $moduleName;
                if (substr($filePath, 0, strlen($modulePath)) === $modulePath) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a module is in the $allowed_modules array
     *
     * @param $moduleName
     *
     * @return bool
     */
    protected function moduleIsAllowed($moduleName)
    {
        return in_array($moduleName, Config::inst()->get('DataObjectAnnotator', 'enabled_modules'), null);
    }

    /**
     * @param $className
     *
     * @return string
     */
    protected function getClassFilePath($className)
    {
        $reflector = new ReflectionClass($className);
        $filePath = $reflector->getFileName();

        if (is_writable($filePath)) {
            return $filePath;
        }

        return false;
    }

    /**
     * Get the file and have the ORM Properties generated.
     *
     * @param String $fileContent
     * @param String $className
     *
     * @return mixed|void
     */
    protected function getFileContentWithAnnotations($fileContent, $className)
    {
        /* Reset the resultString before we continue. Otherwise, it might double-up. */
        $this->resultString = '';
        $this->generateORMProperties($className);

        if (!$this->resultString) {
            return null;
        }

        $startTag = static::STARTTAG;
        $endTag = static::ENDTAG;

        if (strpos($fileContent, $startTag) && strpos($fileContent, $endTag)) {
            $replacement = $startTag . "\n" . $this->resultString . ' * ' . $endTag;

            return preg_replace("/$startTag([\s\S]*?)$endTag/", $replacement, $fileContent);
        } else {
            $classDeclaration = 'class ' . $className . ' extends'; // add extends to exclude Controller writes
            $properties = "\n/**\n * " . $startTag . "\n"
                . $this->resultString
                . " * " . $endTag . "\n"
                . " */\n$classDeclaration";

            return str_replace($classDeclaration, $properties, $fileContent);
        }
    }

    /**
     * Get the literal contents of the DataObject file.
     *
     * @param $fileContent
     *
     * @return mixed
     */
    protected function getFileContentWithoutAnnotations($fileContent)
    {
        $startTag = static::STARTTAG;
        $endTag = static::ENDTAG;

        if (strpos($fileContent, $startTag) && strpos($fileContent, $endTag)) {
            $replace = "/\n\/\*\*\n \* " . $startTag . "\n"
                . "([\s\S]*?)"
                . " \* $endTag"
                . "\n \*\/\n/";

            $fileContent = preg_replace($replace, '', $fileContent);
        }

        return $fileContent;
    }


    /**
     * @param String $className
     *
     * @return string
     */
    protected function generateORMProperties($className)
    {
        /*
         * Loop the available types and generate the ORM property.
         */
        foreach (self::$propertyTypes as $type) {
            $function = 'generateORM' . $type . 'Properties';
            $this->{$function}($className);
        }
    }

    /**
     * Generate the Owner-properties for extensions.
     *
     * @param string $className
     */
    protected function generateORMOwnerProperties($className) {
        $owners = array();
        foreach($this->objectList as $class) {
            $config = Config::inst()->get($class, 'extensions', Config::UNINHERITED);
            if($config !== null && in_array($className, Config::inst()->get($class, 'extensions', Config::UNINHERITED), null)) {
                $owners[] = $class;
            }
        }
        if(count($owners)) {
            $this->resultString .= ' * @property ';
            foreach ($owners as $key => $owner) {
                if ($key > 0) {
                    $this->resultString .= '|';
                }
                $this->resultString .= "$owner";
            }
            $this->resultString .= "|$className owner\n";
        }
    }


    /**
     * Generate the $db property values.
     *
     * @param DataObject|DataExtension $className
     * @return string
     */
    protected function generateORMDBProperties($className)
    {
        if ($fields = Config::inst()->get($className, 'db', Config::UNINHERITED)) {
            foreach ($fields as $fieldName => $dataObjectName) {
                $prop = 'string';

                $fieldObj = Object::create_from_string($dataObjectName, $fieldName);

                if (is_a($fieldObj, 'Int')) {
                    $prop = 'int';
                } elseif (is_a($fieldObj, 'Boolean')) {
                    $prop = 'boolean';
                } elseif (is_a($fieldObj, 'Float') || is_a($fieldObj, 'Decimal')) {
                    $prop = 'float';
                }
                $this->resultString .= " * @property $prop $fieldName\n";
            }
        }

        return true;
    }

    /**
     * Generate the $belongs_to property values.
     *
     * @param DataObject|DataExtension $className
     * @return string
     */
    protected function generateORMBelongsToProperties($className)
    {
        if ($fields = Config::inst()->get($className, 'belongs_to', Config::UNINHERITED)) {
            foreach ($fields as $fieldName => $dataObjectName) {
                $this->resultString .= ' * @property ' . $dataObjectName . " $fieldName\n";
            }
        }

        return true;
    }

    /**
     * Generate the $has_one property and method values.
     *
     * @param DataObject|DataExtension $className
     * @return string
     */
    protected function generateORMHasOneProperties($className)
    {
        if ($fields = Config::inst()->get($className, 'has_one', Config::UNINHERITED)) {
            foreach ($fields as $fieldName => $dataObjectName) {
                $this->resultString .= " * @property int {$fieldName}ID\n";
            }
            foreach ($fields as $fieldName => $dataObjectName) {
                $this->resultString .= " * @method $dataObjectName $fieldName\n";
            }
        }

        return true;
    }

    /**
     * Generate the $has_many method values.
     *
     * @param DataObject|DataExtension $className
     * @return string
     */
    protected function generateORMHasManyProperties($className)
    {
        if ($fields = Config::inst()->get($className, 'has_many', Config::UNINHERITED)) {
            foreach ($fields as $fieldName => $dataObjectName) {
                $this->resultString .= ' * @method DataList|' . $dataObjectName . "[] $fieldName\n";
            }
        }

        return true;
    }

    /**
     * Generate the $many_many method values.
     *
     * @param DataObject|DataExtension $className
     * @return string
     */
    protected function generateORMManyManyProperties($className)
    {
        if ($fields = Config::inst()->get($className, 'many_many', Config::UNINHERITED)) {
            foreach ($fields as $fieldName => $dataObjectName) {
                $this->resultString .= ' * @method ManyManyList|' . $dataObjectName . "[] $fieldName\n";
            }
        }

        return true;
    }

    /**
     * Generate the $belongs_many_many method values.
     *
     * @param DataObject|DataExtension $className
     * @return string
     */
    protected function generateORMBelongsManyManyProperties($className)
    {
        if ($fields = Config::inst()->get($className, 'belongs_many_many', Config::UNINHERITED)) {
            foreach ($fields as $fieldName => $dataObjectName) {
                $this->resultString .= ' * @method ManyManyList|' . $dataObjectName . "[] $fieldName\n";
            }
        }

        return true;
    }

    /**
     * Generate the mixins for DataExtensions
     *
     * @param DataObject|DataExtension $className
     * @return string
     */
    protected function generateORMExtensionsProperties($className)
    {
        if ($fields = Config::inst()->get($className, 'extensions', Config::UNINHERITED)) {
            foreach ($fields as $fieldName) {
                $this->resultString .= " * @mixin $fieldName\n";
            }
        }

        return true;
    }
}
