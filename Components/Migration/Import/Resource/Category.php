<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shopware\SwagMigration\Components\Migration\Import\Resource;

use Shopware\SwagMigration\Components\Migration;
use Exception;
use Shopware\SwagMigration\Components\DbServices\Import\Import;
use Shopware\SwagMigration\Components\Migration\Import\Progress;

/**
 * Shopware SwagMigration Components - Category
 *
 * Category import adapter
 *
 * @category  Shopware
 * @package Shopware\Plugins\SwagMigration\Components\Migration\Import\Resource
 * @copyright Copyright (c) 2012, shopware AG (http://www.shopware.de)
 */
class Category extends AbstractResource
{
    /** @var \Enlight_Components_Db_Adapter_Pdo_Mysql */
    private $db = null;

    /**
     * @return \Enlight_Components_Db_Adapter_Pdo_Mysql
     * @throws Exception
     */
    public function getDb()
    {
        if ($this->db === null) {
            $this->db = Shopware()->Container()->get('db');
        }

        return $this->db;
    }

    /**
     * Returns the default error message for this import class
     *
     * @return mixed
     */
    public function getDefaultErrorMessage()
    {
        if ($this->getInternalName() == 'import_categories') {
            return $this->getNameSpace()->get(
                'errorImportingCategories',
                "An error occurred while importing categories"
            );
        } elseif ($this->getInternalName() == 'import_article_categories') {
            return $this->getNameSpace()->get(
                'errorImportingArticleCategories',
                "An error assigning articles to categories"
            );
        }
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
        if ($this->getInternalName() == 'import_categories') {
            return sprintf(
                $this->getNameSpace()->get('progressCategories', "%s out of %s categories imported"),
                $progress->getOffset(),
                $progress->getCount()
            );
        } elseif ($this->getInternalName() == 'import_article_categories') {
            return sprintf(
                $this->getNameSpace()->get('progressArticleCategories', "%s out of %s articles assigned to categories"),
                $progress->getOffset(),
                $progress->getCount()
            );
        }
    }

    /**
     * Returns the default 'all done' message
     *
     * @return mixed
     */
    public function getDoneMessage()
    {
        return $this->getNameSpace()->get('importedCategories', "Categories successfully imported!");
    }

    /**
     * Set a category target id
     *
     * @param $id
     * @param $target
     */
    public function setCategoryTarget($id, $target)
    {
        $this->deleteCategoryTarget($id);

        $sql = '
            INSERT INTO `s_plugin_migrations` (`typeID`, `sourceID`, `targetID`)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE `targetID`=VALUES(`targetID`);
        ';

        $this->getDb()->query($sql, [Migration::MAPPING_CATEGORY_TARGET, $id, $target]);
    }

    /**
     * Get a category target id
     *
     * @param $id
     * @return bool|string
     */
    public function getCategoryTarget($id)
    {
        if (!isset($id) || empty($id)) {
            return false;
        }

        return $this->getDb()->fetchOne(
            "SELECT `targetID` FROM `s_plugin_migrations` WHERE typeID=? AND sourceID=?",
            [Migration::MAPPING_CATEGORY_TARGET, $id]
        );
    }

    /**
     * Get a category target id
     *
     * @param $id
     * @return bool|string
     */
    public function getCategoryTargetLike($id)
    {
        if (!isset($id) || empty($id)) {
            return false;
        }

        return $this->getDb()->fetchOne(
            "SELECT `targetID` FROM `s_plugin_migrations` WHERE typeID=? AND sourceID LIKE ?",
            [
                Migration::MAPPING_CATEGORY_TARGET,
                $id . Migration::CATEGORY_LANGUAGE_SEPARATOR . '%'
            ]
        );
    }

    /**
     * Delete category target
     *
     * @param $id
     */
    public function deleteCategoryTarget($id)
    {
        $sql = "DELETE FROM s_plugin_migrations WHERE typeID = ? AND sourceID = '{$id}'";
        $this->getDb()->query($sql, [Migration::MAPPING_CATEGORY_TARGET]);
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
     * - $this->increaseProgress() to increase the offset/progress by one
     * - $this->getProgress()->getOffset() to get the current progress' offset
     * - return $this->getProgress()->error("Message") in order to stop with an error message
     * - return $this->getProgress() in order to be called again with the current offset
     * - return $this->getProgress()->done() in order to mark the import as finished
     *
     * The category import adapter handles categories as well as article-category assignments.
     *
     * @return Progress
     */
    public function run()
    {
        if ($this->getInternalName() == 'import_categories') {
            return $this->importCategories();
        } elseif ($this->getInternalName() == 'import_article_categories') {
            return $this->importArticleCategories();
        }
    }

    /**
     * Will import the actual categories
     *
     * @return $this|Progress
     */
    public function importCategories()
    {
        $offset = $this->getProgress()->getOffset();

        $skip = false;

        // Cleanup previous category imports
        if (!$skip && $offset === 0) {
            $this->getDb()->query(
                "DELETE FROM s_plugin_migrations WHERE typeID IN (?, ?);",
                [Migration::MAPPING_CATEGORY_TARGET, 2]
            );
        }

        $categories = $this->Source()->queryCategories($offset);
        $count = $categories->rowCount() + $offset;
        $this->getProgress()->setCount($count);
        $this->initTaskTimer();

        while (!$skip && $category = $categories->fetch()) {
            //check if the category split into the different translations
            if (!empty($category['languageID'])
                && strpos($category['categoryID'], Migration::CATEGORY_LANGUAGE_SEPARATOR) === false
            ) {
                $category['categoryID'] = $category['categoryID'] . Migration::CATEGORY_LANGUAGE_SEPARATOR . $category['languageID'];

                if (!empty($category['parentID'])) {
                    $category['parentID'] = $category['parentID'] . Migration::CATEGORY_LANGUAGE_SEPARATOR . $category['languageID'];
                }
            }

            $target_parent = $this->getCategoryTarget($category['parentID']);
            // More generous approach - will ignore languageIDs
            if (empty($target_parent) && !empty($category['parentID'])) {
                $target_parent = $this->getCategoryTargetLike($category['parentID']);
            }
            // Do not create empty categories
            if (empty($category['description'])) {
                $this->increaseProgress();
                continue;
            }

            if (!empty($category['parentID'])) {
                // Map the category IDs
                if (false !== $target_parent) {
                    $category['parent'] = $target_parent;
                } else {
                    if (empty($target_parent)) {
                        error_log("Parent category not found: {$category['parentID']}. Will not create '{$category['description']}'");
                        $this->increaseProgress();
                        continue;
                    }
                }
            } elseif (!empty($category['languageID'])
                && !empty($this->Request()->language)
                && !empty($this->Request()->language[$category['languageID']])
            ) {
                $sql = 'SELECT `category_id` FROM `s_core_shops` WHERE `locale_id`=?';
                $category['parent'] = $this->getDb()->fetchOne($sql, [$this->Request()->language[$category['languageID']]]);
            }

            try {
                /* @var Import $import */
                $import = Shopware()->Container()->get('swagmigration.import');
                $category['targetID'] = $import->category($category);

                $this->setCategoryTarget($category['categoryID'], $category['targetID']);
                // if meta_title isset update the Category
                if (!empty($category['meta_title'])) {
                    $this->getDb()->update(
                        's_categories',
                        ['meta_title' => $category['meta_title']],
                        ['id=?' => $category['targetID']]
                    );
                }
            } catch (Exception $e) {
                echo "<pre>";
                print_r($e);
                echo "</pre>";
                exit();
            }

            $sql = '
                INSERT INTO `s_plugin_migrations` (`typeID`, `sourceID`, `targetID`)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE `targetID`=VALUES(`targetID`);
            ';

            $this->getDb()->query($sql, [Migration::MAPPING_CATEGORY, $category['categoryID'], $category['targetID']]);

            $this->increaseProgress();
            if ($this->newRequestNeeded()) {
                return $this->getProgress();
            }
        }

        $this->getProgress()->addRequestParam('import_article_categories', 1);

        return $this->getProgress()->done();
    }

    /**
     * Will assign articles to categories
     *
     * @return Progress
     */
    public function importArticleCategories()
    {
        $offset = $this->getProgress()->getOffset();

        $result = $this->Source()->queryProductCategories($offset);

        $count = $result->rowCount() + $offset;
        $this->getProgress()->setCount($count);

        $this->initTaskTimer();

        /* @var Import $import */
        $import = Shopware()->Container()->get('swagmigration.import');

        while ($productCategory = $result->fetch()) {
            if ($this->newRequestNeeded()) {
                return $this->getProgress();
            }
            $this->increaseProgress();

            $sql = '
                SELECT ad.articleID
                FROM s_plugin_migrations pm
                JOIN s_articles_details ad ON ad.id = pm.targetID
                WHERE sourceID = ? AND typeID = ?
            ';
            $article = $this->getDb()->fetchOne($sql, [$productCategory['productID'], Migration::MAPPING_ARTICLE]);
            if (empty($article)) {
                continue;
            }

            $sql = '
                SELECT `targetID`
                FROM `s_plugin_migrations`
                WHERE `typeID`=? AND (`sourceID`=? OR `sourceID` LIKE ?)
            ';
            // Also take language categories into account
            $categories = $this->getDb()->fetchCol(
                $sql,
                [
                    Migration::MAPPING_CATEGORY,
                    $productCategory['categoryID'],
                    $productCategory['categoryID'] . Migration::CATEGORY_LANGUAGE_SEPARATOR . '%'
                ]
            );

            if (empty($categories)) {
                continue;
            }

            foreach ($categories as $category) {
                $import->articleCategory($article, $category);
            }
        }

        $this->getProgress()->done();
    }
}
