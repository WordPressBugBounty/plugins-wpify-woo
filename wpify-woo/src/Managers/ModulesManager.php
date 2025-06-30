<?php

namespace WpifyWoo\Managers;

use WpifyWoo\Modules\AsyncEmails\AsyncEmailsModule;
use WpifyWoo\Modules\Comments\CommentsModule;
use WpifyWoo\Modules\DeliveryDates\DeliveryDatesModule;
use WpifyWoo\Modules\EmailAttachments\EmailAttachmentsModule;
use WpifyWoo\Modules\FreeShippingNotice\FreeShippingNoticeModule;
use WpifyWoo\Modules\HeurekaMereniKonverzi\HeurekaMereniKonverziModule;
use WpifyWoo\Modules\HeurekaOverenoZakazniky\HeurekaOverenoZakaznikyModule;
use WpifyWoo\Modules\IcDic\IcDicModule;
use WpifyWoo\Modules\Prices\PricesModule;
use WpifyWoo\Modules\PricesLog\PricesLogModule;
use WpifyWoo\Modules\QRPayment\QRPaymentModule;
use WpifyWoo\Modules\SklikRetargeting\SklikRetargetingModule;
use WpifyWoo\Modules\Template\TemplateModule;
use WpifyWoo\Modules\ZboziConversions\ZboziConversionsModule;
use WpifyWoo\Modules\Vocative\VocativeModule;
use WpifyWoo\Modules\XmlFeedHeureka\XmlFeedHeurekaModule;
use WpifyWoo\Plugin;
use WpifyWoo\WooCommerceIntegration;
use WpifyWooDeps\Wpify\WooCore\WpifyWooCore;
use WpifyWooDeps\Wpify\WooCore\Abstracts\AbstractModule;

/**
 * Class ApiManager
 *
 * @package WpifyWoo\Managers
 * @property Plugin $plugin
 */
class ModulesManager {
	const OPTION_NAME = 'wpify-woo-settings';

	public function __construct(
		private WpifyWooCore $wpify_woo_core,
	) {

		$this->load_components();
	}

	protected $modules = array();
	private $async_emails = AsyncEmailsModule::class;
	private $ic_dic = IcDicModule::class;
	private $heureka_overeno_zakazniky = HeurekaOverenoZakaznikyModule::class;
	private $heureka_mereni_konverzi = HeurekaMereniKonverziModule::class;
	private $free_shipping_notice = FreeShippingNoticeModule::class;
	private $vocative = VocativeModule::class;
	private $qr_payment = QRPaymentModule::class;
	private $xml_feed_heureka = XmlFeedHeurekaModule::class;
	private $sklik_retargeting = SklikRetargetingModule::class;
	private $zbozi_conversions_lite = ZboziConversionsModule::class;
	private $template = TemplateModule::class;
	private $email_attachments = EmailAttachmentsModule::class;
	private $prices = PricesModule::class;
	private $prices_log = PricesLogModule::class;
	private $comments = CommentsModule::class;
	private $delivery_dates = DeliveryDatesModule::class;


	private array $modules_ids = [
		'async_emails',
		'ic_dic',
		'heureka_overeno_zakazniky',
		'heureka_mereni_konverzi',
		'xml_feed_heureka',
		'free_shipping_notice',
		'vocative',
		'qr_payment',
		'sklik_retargeting',
		'zbozi_conversions_lite',
		'template',
		'email_attachments',
		'prices',
		'prices_log',
		'comments',
		'delivery_dates',
	];

	public function get_module_by_id( $module ) {
		if ( ! property_exists( $this, $module ) ) {
			return null;
		}

		return wpify_woo_container()->get( $this->{$module} );
	}


	public function load_components() {
		foreach ( $this->modules_ids as $module ) {
			if ( $this->is_module_enabled( $module ) && property_exists( $this, $module ) ) {
				$module = $this->get_module_by_id( $module );
				$this->wpify_woo_core->get_modules_manager()->add_module( $module->id(), $module );
			}
		}
	}

	public function get_modules(): array {
		$modules = array(
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Async emails', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/asynchronni-odesilani-e-mailu/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'Async emails', 'wpify-woo' ),
				'value' => 'async_emails',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Checkout IČ and DIČ', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/ic-dic/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'Checkout IČ and DIČ', 'wpify-woo' ),
				'value' => 'ic_dic',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Heureka ověřeno zákazníky', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/heureka-overeno-zakazniky/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'Heureka ověřeno zákazníky', 'wpify-woo' ),
				'value' => 'heureka_overeno_zakazniky',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Heureka měření konverzí', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/heureka-mereni-konverzi/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'Heureka měření konverzí', 'wpify-woo' ),
				'value' => 'heureka_mereni_konverzi',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'XML Feed Heureka', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/xml-feed-heureka/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'XML Feed Heureka', 'wpify-woo' ),
				'value' => 'xml_feed_heureka',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Free shipping notice', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/notifikace-pro-dopravu-zdarma/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'Free shipping notice', 'wpify-woo' ),
				'value' => 'free_shipping_notice',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Emails Vocative', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/paty-pad-v-e-mailech/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'Emails Vocative', 'wpify-woo' ),
				'value' => 'vocative',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'QR Payment', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/qr-platba/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'QR Payment', 'wpify-woo' ),
				'value' => 'qr_payment',
			),
			array(
				'label'    => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Sklik retargeting', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/sklik-retargeting/', __( 'Documentation', 'wpify-woo' ) ),
				'title'    => __( 'Sklik retargeting', 'wpify-woo' ),
				'doc_link' => 'https://wpify.io/dokumentace/wpify-woo/sklik-retargeting/',
				'value'    => 'sklik_retargeting',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Zbozi.cz/Sklik Conversions Limited', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/zbozi-sklik-konverze/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'Zbozi.cz/Sklik Conversions Limited', 'wpify-woo' ),
				'value' => 'zbozi_conversions_lite',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Template', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/sablona/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'Template', 'wpify-woo' ),
				'value' => 'template',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Email attachments', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/prilohy-emailu/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'Email attachments', 'wpify-woo' ),
				'value' => 'email_attachments',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Prices', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/ceny/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'Prices', 'wpify-woo' ),
				'value' => 'prices',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Prices log', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/historie-cen/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'Prices log', 'wpify-woo' ),
				'value' => 'prices_log',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Comments', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/komentare/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'Comments', 'wpify-woo' ),
				'value' => 'comments',
			),
			array(
				'label' => sprintf( '<h3>%1$s</h3> <a href="%2$s" target="_blank">%3$s</a>', __( 'Delivery dates', 'wpify-woo' ), 'https://wpify.io/dokumentace/wpify-woo/terminy-doruceni/', __( 'Documentation', 'wpify-woo' ) ),
				'title' => __( 'Delivery dates', 'wpify-woo' ),
				'value' => 'delivery_dates',
			),
		);

		foreach ( $modules as $key => $module ) {
			if ( $this->is_module_enabled( $module['value'] ) && property_exists( $this, $module['value'] ) ) {
				/** @var AbstractModule $module_obj */
				$module_obj = $this->get_module_by_id( $module['value'] );

				$modules[ $key ]['label'] = sprintf( '%1$s <a href="%2$s" class="button">%3$s</a>', $module['label'], $module_obj->get_settings_url(), __( 'Settings', 'wpify-woo' ) );
			}
		}

		return $modules;
	}

	/**
	 * Check if a module is enabled
	 *
	 * @param string $module Module name.
	 *
	 * @return bool
	 */
	public function is_module_enabled( string $module ): bool {
		return in_array( $module, $this->get_enabled_modules(), true );
	}

	/**
	 * Get an array of enabled modules
	 *
	 * @return array
	 */
	public function get_enabled_modules(): array {
		return $this->get_settings( 'general' )['enabled_modules'] ?? array();
	}

	/**
	 * Get settings for a specific module
	 *
	 * @param string $module Module name.
	 *
	 * @return array
	 */
	public function get_settings( string $module ): array {
		return get_option( $this->get_settings_name( $module ), array() );
	}

	public function get_settings_name( string $module ): string {
		return sprintf( '%s-%s', $this::OPTION_NAME, $module );
	}
}
