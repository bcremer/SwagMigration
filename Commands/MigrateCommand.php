<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shopware\SwagMigration\Commands;

use Shopware\Commands\ShopwareCommand;
use Shopware\SwagMigration\Components\Migration;
use Shopware\SwagMigration\Components\Migration\Import\Progress;
use Shopware\SwagMigration\Components\Migration\Import\Resource\AbstractResource;
use Shopware\SwagMigration\Components\Migration\Profile;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @category  Shopware
 * @package   Shopware\Components\Console\Commands
 * @copyright Copyright (c) shopware AG (http://www.shopware.de)
 */
class MigrateCommand extends ShopwareCommand
{
    public $imports = [
        'import_products'                     => 'Product',
        'import_translations'                 => 'Translation',
        'import_properties'                   => 'Property',
        'import_categories'                   => 'Category',
        'import_article_categories'           => 'Category',
        'import_prices'                       => 'Price',
        'import_generate_variants'            => 'Configurator',
        'import_create_configurator_variants' => 'Variant',
        'import_images'                       => 'Image',
        'import_customers'                    => 'Customer',
        'import_ratings'                      => 'Rating',
        'import_orders'                       => 'Order',
        'import_order_details'                => 'Order',
        'import_downloads'                    => 'Download',
        'import_downloads_esd'                => 'DownloadESD',
        'import_orders_esd'                   => 'DownloadESDOrder'
    ];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('sw:migration');
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $query = 'profile=Magento&username=root&password=root&host=localhost&port=default&prefix=default&database=hagen_magento&import_products=on&import_translations=on&import_categories=on&supplier=Default&basepath=&salt=&number_validation_mode=ignore&tasks=3&language%5B1%5D=1&language%5B2%5D=3&language%5B3%5D=4&price_group%5B0%5D=EK&price_group%5B1%5D=EK&price_group%5B8%5D=EK&shop%5B1%5D=1&shop%5B2%5D=3&shop%5B3%5D=4&attribute%5Bin_depth%5D=&attribute%5Bmeta_description%5D=&attribute%5Bmeta_title%5D=&attribute%5Bresponse_time%5D=&attribute%5Burl_key%5D=&attribute%5Bvisibility%5D=&attribute%5Bashtray_large%5D=&attribute%5Bbelt_length%5D=&attribute%5Bbelt_style%5D=&attribute%5Bcartridgepocketsandelements%5D=&attribute%5Bcolor%5D=&attribute%5Bcufflings_design%5D=&attribute%5Bfoxfurpillow_style_size%5D=&attribute%5Bfurblankets_style%5D=&attribute%5Bfurcarpet_style%5D=&attribute%5Bheadwear_size%5D=&attribute%5Bhuntershoesizes%5D=&attribute%5Bhunting_coat_size%5D=&attribute%5Bjacket_size%5D=&attribute%5Bmanufacturer_information%5D=&attribute%5Bmotives_gifts_interior%5D=&attribute%5Bmustard_jam_pot%5D=&attribute%5Bpants_size%5D=&attribute%5Bshirt_size_ladies%5D=&attribute%5Bshirt_size%5D=&attribute%5Bshoe_size%5D=&attribute%5Bshootingcoat_rixa_size%5D=&attribute%5Bshootingcoat_rixa_style%5D=&attribute%5Bshooting_breeks_tweed_size%5D=&attribute%5Bshooting_breeks_tweed_style%5D=&attribute%5Bsilk_paisley_tie%5D=&attribute%5Bsize%5D=&attribute%5Bstockings_classics_color%5D=&attribute%5Bstockings_entry_color%5D=&attribute%5Bstockings_premium_color%5D=&attribute%5Bstockings_size%5D=&attribute%5Btie_style%5D=&order_status%5Bpending%5D=&order_status%5Bholded%5D=&order_status%5Bprocessing%5D=&order_status%5Bcomplete%5D=2&payment_mean%5Bcheckmo%5D=&payment_mean%5B%5D=&payment_mean%5Bpaypal_standard%5D=&payment_mean%5Bfree%5D=&property_options%5Bmanufacturer%5D=&property_options%5Bcolor%5D=&property_options%5Bsize%5D=&property_options%5Bmanufacturer_information%5D=&tax_rate%5B1%5D=&tax_rate%5B2%5D=&tax_rate%5B3%5D=&action=importName';
        $request = $this->createRequest($query);

        $steps = array(
            'import_products',
            'import_translations',
            //'import_properties',
            'import_categories',
            'import_article_categories',
            'import_prices',
            //'import_generate_variants',
            //'import_create_configurator_variants',
        );

        foreach ($steps as $name) {
            $output->writeln('Processing: '. $name);
            $import = $this->createImport($name, $request);
            $import->run();
        }

        $output->writeln("End");
    }

    /**
     * Creates an instance of the import resource needed to import $importType
     *
     * Will also inject the dependencies needed and return the created object
     *
     * @param string $internalName
     * @param \Enlight_Controller_Request_Request $request
     * @return AbstractResource
     */
    public function createImport($internalName, \Enlight_Controller_Request_Request $request)
    {
        $progress = new Progress();
        $progress->setOffset(0);

        $name = $this->imports[$internalName];

        $import = Migration::resourceFactory(
            $name,
            $progress,
            $this->createSource(),
            $this->createTarget(),
            $request
        );

        $import->setInternalName($internalName);
        $import->setMaxExecution(PHP_INT_MAX);

        return $import;
    }

    /**
     * @return Profile
     */
    public function createTarget()
    {
        $config = (array) Shopware()->getOption('db');

        return Migration::profileFactory('Shopware', $config);
    }

    /**
     * This function inits the source profile and creates it over the profile factory
     *
     * @return Profile
     */
    public function createSource()
    {
        //        $config = (array) Shopware()->getOption('db');
        $profile = 'Magento';

        $config = array(
            'username' => 'root',
            'password' => 'root',
            'host'     => 'localhost',
            'dbname'   => 'hagen_magento',
        );

        return Migration::profileFactory($profile, $config);
    }

    /**
     * @param string $query
     * @return \Enlight_Controller_Request_Request
     */
    protected function createRequest($query)
    {
        $result = array();
        parse_str($query, $result);

        $request = new \Enlight_Controller_Request_RequestHttp();

        foreach ($result as $key => $value) {
            $request->setParam($key, $value);
        }

        return $request;
    }
}
