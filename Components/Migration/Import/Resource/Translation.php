<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shopware\SwagMigration\Components\Migration\Import\Resource;

use Shopware\SwagMigration\Components\Migration\Import\Progress;
use Shopware\SwagMigration\Components\Migration;
use Shopware\SwagMigration\Components\DbServices\Import\Import;

/**
 * Shopware SwagMigration Components - Translation
 *
 * Translation import adapter
 *
 * @category  Shopware
 * @package Shopware\Plugins\SwagMigration\Components\Migration\Import\Resource
 * @copyright Copyright (c) 2012, shopware AG (http://www.shopware.de)
 */
class Translation extends AbstractResource
{
    /**
     * Returns the default error message for this import class
     *
     * @return mixed
     */
    public function getDefaultErrorMessage()
    {
        return $this->getNameSpace()->get(
            'errorImportingTranslations',
            "An error occurred while importing translations"
        );
    }

    /**
     * Returns the progress message for the current import step. A Progress-Object will be passed, so
     * you can get some context info for your snippet
     *
     * @param Progress $progress
     * @return string
     */
    public function getCurrentProgressMessage($progress)
    {
        return sprintf(
            $this->getNameSpace()->get('progressTranslations', "%s out of %s translations imported"),
            $this->getProgress()->getOffset(),
            $this->getProgress()->getCount()
        );
    }

    /**
     * Returns the default 'all done' message
     *
     * @return mixed
     */
    public function getDoneMessage()
    {
        return $this->getNameSpace()->get('importedTranslations', "Translations successfully imported!");
    }

    /**
     * Main run method of each import adapter. The run method will query the source profile, iterate
     * the results and prepare the data for import via the old Shopware API.
     *
     * If you want to import multiple entities with one import-class, you might want to check for
     * $this->getInternalName() in order to distinct which (sub)entity you where called for.
     *
     * The run method may only return instances of Progress
     * The calling instance will use those progress object to communicate with the ExtJS backend.
     * If you want this to work properly, think of calling:
     * - $this->initTaskTimer() at the beginning of your run method
     * - $this->getProgress()->setCount(222) to set the total number of data
     * - $this->increaseProgress() to increase the offset/progress
     * - $this->getProgress()->getOffset() to get the current progress' offset
     * - return $this->getProgress()->error("Message") in order to stop with an error message
     * - return $this->getProgress() in order to be called again with the current offset
     * - return $this->getProgress()->done() in order to mark the import as finished
     *
     *
     * @return Progress
     */
    public function run()
    {
        $offset = $this->getProgress()->getOffset();

        $result = $this->Source()->queryProductTranslations($offset);
        $count = $result->rowCount() + $offset;
        $this->getProgress()->setCount($count);

        $this->initTaskTimer();

        /* @var Import $import */
        $import = Shopware()->Container()->get('swagmigration.import');

        while ($translation = $result->fetch()) {
            //Attribute
            if (!empty($this->Request()->attribute)) {
                foreach ($this->Request()->attribute as $source => $target) {
                    if (!empty($target) && isset($translation[$source])) {
                        $translation[$target] = $translation[$source];
                        unset($translation[$source]);
                    }
                }
            }

            //set the language id of the translation
            if (isset($this->Request()->language[$translation['languageID']])) {
                $translation['languageID'] = $this->Request()->language[$translation['languageID']];
            }

            //get the product data
            $sql = '
                SELECT ad.articleID, ad.id AS articledetailsID, kind
                FROM s_plugin_migrations pm
                JOIN s_articles_details ad
                ON ad.id=pm.targetID
                WHERE pm.`sourceID`=?
                AND `typeID`=?
            ';
            $product_data = Shopware()->Db()->fetchRow($sql, [$translation['productID'], Migration::MAPPING_ARTICLE]);

            if (!empty($product_data)) {
                $translation['articletranslationsID'] = $import->translation(
                    $product_data['kind'] == 1 ? 'article' : 'variant',
                    $product_data['kind'] == 1 ? $product_data['articleID'] : $product_data['articledetailsID'],
                    $translation['languageID'],
                    $translation
                );
            }

            $this->increaseProgress();
            if ($this->newRequestNeeded()) {
                return $this->getProgress();
            }
        }

        return $this->getProgress()->done();
    }
}
