<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace DieSchittigs\ContaoContentApiBundle;

use Contao\Input;
use Contao\System;
use Contao\Module;
use Contao\Config;
use Contao\PageModel;
use Contao\FilesModel;
use Contao\ThemeModel;
use Contao\ModuleModel;
use Contao\ModuleArticle;
use Contao\Environment;
use Contao\ContentElement;
use Contao\LayoutModel;
use Contao\ArticleModel;
use Contao\PageRegular;
use Contao\ContentModel;
use Contao\StringUtil;
use Contao\FrontendTemplate;
use Contao\CoreBundle\Exception\NoLayoutSpecifiedException;
use Contao\CoreBundle\Util\PackageUtil;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provide methods to handle a regular front end page.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class PageApi extends PageRegular
{

	protected $Template = [];

	/**
	 * Generate a regular page
	 *
	 * @param PageModel $objPage
	 * @param boolean   $blnCheckRequest
	 */
	public function generate($objPage, $blnCheckRequest=false)
	{
		$this->prepare($objPage);
		return $this->Template->getData();
	}

	/**
	 * Return a response object
	 *
	 * @param PageModel $objPage
	 * @param boolean   $blnCheckRequest
	 *
	 * @return Response
	 */
	public function getResponse($objPage, $blnCheckRequest=false)
	{
		$this->prepare($objPage);
		return $this->Template->getData();
	}

	/**
	 * Generate a regular page
	 *
	 * @param PageModel $objPage
	 *
	 * @internal Do not call this method in your code. It will be made private in Contao 5.0.
	 */
	protected function prepare($objPage)
	{
		$GLOBALS['TL_KEYWORDS'] = '';
		$GLOBALS['TL_LANGUAGE'] = $objPage->language;

		$locale = str_replace('-', '_', $objPage->language);

		$container = System::getContainer();
		$container->get('request_stack')->getCurrentRequest()->setLocale($locale);
		$container->get('translator')->setLocale($locale);

		System::loadLanguageFile('default');

		// Static URLs
		$this->setStaticUrls();

		// Get the page layout
		$objLayout = $this->getPageLayout($objPage);

		// // HOOK: modify the page or layout object (see #4736)
		// if (isset($GLOBALS['TL_HOOKS']['getPageLayout']) && \is_array($GLOBALS['TL_HOOKS']['getPageLayout']))
		// {
		// 	foreach ($GLOBALS['TL_HOOKS']['getPageLayout'] as $callback)
		// 	{
		// 		$this->import($callback[0]);
		// 		$this->{$callback[0]}->{$callback[1]}($objPage, $objLayout, $this);
		// 	}
		// }

		/** @var ThemeModel $objTheme */
		$objTheme = $objLayout->getRelated('pid');

		// Set the default image densities
		$container->get('contao.image.picture_factory')->setDefaultDensities($objTheme->defaultImageDensities);

		// Store the layout ID
		$objPage->layoutId = $objLayout->id;

		// Set the layout template and template group
		$objPage->template = $objLayout->template ?: 'fe_page';
		$objPage->templateGroup = $objTheme->templates;

		// Minify the markup
		$objPage->minifyMarkup = $objLayout->minifyMarkup;

		// Initialize the template
		$this->createTemplate($objPage, $objLayout);

		// Initialize modules and sections
		$arrCustomSections = array();
		$arrSections = array('header', 'left', 'right', 'main', 'footer');
		$arrModules = StringUtil::deserialize($objLayout->modules);

		$arrModuleIds = array();

		// Filter the disabled modules
		foreach ($arrModules as $module)
		{
			if ($module['enable'])
			{
				$arrModuleIds[] = $module['mod'];
			}
		}

		// Get all modules in a single DB query
		$objModules = ModuleModel::findMultipleByIds($arrModuleIds);

		$arrArticles = $this->Template->articles;;
		if ($objModules !== null || \in_array(0, $arrModuleIds)) // see #4137
		{
			$arrMapper = array();

			// Create a mapper array in case a module is included more than once (see #4849)
			if ($objModules !== null)
			{
				while ($objModules->next())
				{
					$arrMapper[$objModules->id] = $objModules->current();
				}
			}

			foreach ($arrModules as $arrModule)
			{
				// Disabled module
				if (!$arrModule['enable'] && !BE_USER_LOGGED_IN)
				{
					continue;
				}

				// Replace the module ID with the module model
				if ($arrModule['mod'] > 0 && isset($arrMapper[$arrModule['mod']]))
				{
					$arrModule['mod'] = $arrMapper[$arrModule['mod']];
				}

				// Generate the modules
				if (\in_array($arrModule['col'], $arrSections))
				{
					// Filter active sections (see #3273)
					if ($arrModule['col'] == 'header' && $objLayout->rows != '2rwh' && $objLayout->rows != '3rw')
					{
						continue;
					}
					if ($arrModule['col'] == 'left' && $objLayout->cols != '2cll' && $objLayout->cols != '3cl')
					{
						continue;
					}
					if ($arrModule['col'] == 'right' && $objLayout->cols != '2clr' && $objLayout->cols != '3cl')
					{
						continue;
					}
					if ($arrModule['col'] == 'footer' && $objLayout->rows != '2rwf' && $objLayout->rows != '3rw')
					{
						continue;
					}


					$arrArticles[$arrModule['col']] = array_merge($arrArticles[$arrModule['col']], $this->getFrontendModuleData($arrModule['mod'], $arrModule['col']));
				}
				else
				{
					$arrCustomSections[$arrModule['col']] = array_merge($arrCustomSections[$arrModule['col']], $this->getFrontendModuleData($arrModule['mod'], $arrModule['col']));
				}
			}
		}


		$this->Template->articles = $arrArticles;
		$this->Template->sections = $arrCustomSections;

		// Mark RTL languages (see #7171)
		if ($GLOBALS['TL_LANG']['MSC']['textDirection'] == 'rtl')
		{
			$this->Template->isRTL = true;
		}

		// // HOOK: modify the page or layout object
		// if (isset($GLOBALS['TL_HOOKS']['generatePage']) && \is_array($GLOBALS['TL_HOOKS']['generatePage']))
		// {
		// 	foreach ($GLOBALS['TL_HOOKS']['generatePage'] as $callback)
		// 	{
		// 		$this->import($callback[0]);
		// 		$this->{$callback[0]}->{$callback[1]}($objPage, $objLayout, $this);
		// 	}
		// }

		// Set the page title and description AFTER the modules have been generated
		$this->Template->mainTitle = $objPage->rootPageTitle;
		$this->Template->pageTitle = $objPage->pageTitle ?: $objPage->title;

		// Meta robots tag
		$this->Template->robots = $objPage->robots ?: 'index,follow';

		// Remove shy-entities (see #2709)
		$this->Template->mainTitle = str_replace('[-]', '', $this->Template->mainTitle);
		$this->Template->pageTitle = str_replace('[-]', '', $this->Template->pageTitle);

		// Fall back to the default title tag
		if ($objLayout->titleTag == '')
		{
			$objLayout->titleTag = '{{page::pageTitle}} - {{page::rootPageTitle}}';
		}

		// Assign the title and description
		$this->Template->title = StringUtil::stripInsertTags($this->replaceInsertTags($objLayout->titleTag)); // see #7097
		$this->Template->description = str_replace(array("\n", "\r", '"'), array(' ', '', ''), $objPage->description);

		// Body onload and body classes
		$this->Template->onload = trim($objLayout->onload);
		$this->Template->class = trim($objLayout->cssClass . ' ' . $objPage->cssClass);

		// Execute AFTER the modules have been generated and create footer scripts first
		$this->createFooterScripts($objLayout);
		$this->createHeaderScripts($objPage, $objLayout);
	}

	/**
	 * Generate a front end module and return it as string
	 *
	 * @param mixed  $intId     A module ID or a Model object
	 * @param string $strColumn The name of the column
	 *
	 * @return string The module HTML markup
	 */
	public function getFrontendModuleData($intId, $strColumn='main') : array
	{
		if (!\is_object($intId) && !\strlen($intId))
		{
			return [];
		}

		/** @var PageModel $objPage */
		global $objPage;

		// Articles
		if (!\is_object($intId) && $intId == 0)
		{
			// Show all articles (no else block here, see #4740)
			$objArticles = ArticleModel::findPublishedByPidAndColumn($objPage->id, $strColumn);

			if ($objArticles === null)
			{
				return [];
			}

			$return = [];
			$intCount = 0;
			$blnMultiMode = ($objArticles->count() > 1);
			$intLast = $objArticles->count() - 1;

			while ($objArticles->next())
			{
				/** @var ArticleModel $objRow */
				$objRow = $objArticles->current();

				// Add the "first" and "last" classes (see #2583)
				if ($intCount == 0 || $intCount == $intLast)
				{
					$arrCss = array();

					if ($intCount == 0)
					{
						$arrCss[] = 'first';
					}

					if ($intCount == $intLast)
					{
						$arrCss[] = 'last';
					}

					$objRow->classes = $arrCss;
				}

				$return[] = $this->getArticleData($objRow, $blnMultiMode, false, $strColumn);
				++$intCount;
			}

			return $return;
		}

		// Other modules
		else
		{
			if (\is_object($intId))
			{
				$objRow = $intId;
			}
			else
			{
				$objRow = ModuleModel::findByPk($intId);

				if ($objRow === null)
				{
					return [];
				}
			}

			// Check the visibility (see #6311)
			if (!static::isVisibleElement($objRow))
			{
				return [];
			}

			$strClass = Module::findClass($objRow->type);

			// Return if the class does not exist
			if (!class_exists($strClass))
			{
				static::log('Module class "'.$strClass.'" (module "'.$objRow->type.'") does not exist', __METHOD__, TL_ERROR);

				return [];
			}

			$objRow->typePrefix = 'mod_';

			/** @var Module $objModule */
			$objModule = new $strClass($objRow, $strColumn);
			$objModule->generate();

			// $objModule = Helper::toObj($objModule);
			
			$Data = $objModule->Template->getData();
			$Data['modulType'] = 'module';
			unset($Data['Template']);
			return [$Data];
		}

		return [];
	}

	/**
	 * Generate an article and return it as string
	 *
	 * @param mixed   $varId          The article ID or a Model object
	 * @param boolean $blnMultiMode   If true, only teasers will be shown
	 * @param boolean $blnIsInsertTag If true, there will be no page relation
	 * @param string  $strColumn      The name of the column
	 *
	 * @return string|boolean The article HTML markup or false
	 */
	public function getArticleData($varId, $blnMultiMode=false, $blnIsInsertTag=false, $strColumn='main')
	{
		/** @var PageModel $objPage */
		global $objPage;

		if (\is_object($varId))
		{
			$objRow = $varId;
		}
		else
		{
			if (!$varId)
			{
				return '';
			}

			$objRow = ArticleModel::findByIdOrAliasAndPid($varId, (!$blnIsInsertTag ? $objPage->id : null));

			if ($objRow === null)
			{
				return false;
			}
		}

		// Check the visibility (see #6311)
		if (!static::isVisibleElement($objRow))
		{
			return '';
		}

		$objRow->modulType = 'article';

		$objRow->headline = $objRow->title;
		$objRow->multiMode = $blnMultiMode;

		$objArticle = new ModuleArticle($objRow, $strColumn);
		$objArticle->generate();
		$Data = $objArticle->Template->getData();

		$Data['content'] = $this->getArticleElements($objArticle);
		unset($Data['Template']);
		return $Data;
	}

	protected function getArticleElements($objArticle)
	{
		$arrElements = array();
		$objCte = ContentModel::findPublishedByPidAndTable($objArticle->id, 'tl_article');

		if ($objCte !== null)
		{
			$intCount = 0;
			$intLast = $objCte->count() - 1;

			while ($objCte->next())
			{
				$arrCss = array();

				/** @var ContentModel $objRow */
				$objRow = $objCte->current();

				// Add the "first" and "last" classes (see #2583)
				if ($intCount == 0 || $intCount == $intLast)
				{
					if ($intCount == 0)
					{
						$arrCss[] = 'first';
					}

					if ($intCount == $intLast)
					{
						$arrCss[] = 'last';
					}
				}

				$objRow->classes = $arrCss;
				$arrElements[] = $this->getContentElementData($objRow, $objArticle->strColumn);
				++$intCount;
			}
		}

		return $arrElements;
	}
	/**
	 * Generate a content element and return it as string
	 *
	 * @param mixed  $intId     A content element ID or a Model object
	 * @param string $strColumn The column the element is in
	 *
	 * @return string The content element HTML markup
	 */
	public function getContentElementData($intId, $strColumn='main')
	{
		if (\is_object($intId))
		{
			$objRow = $intId;
		}
		else
		{
			if (!\strlen($intId) || $intId < 1)
			{
				return '';
			}

			$objRow = ContentModel::findByPk($intId);

			if ($objRow === null)
			{
				return '';
			}
		}

		// Check the visibility (see #6311)
		if (!static::isVisibleElement($objRow))
		{
			return '';
		}

		$strClass = ContentElement::findClass($objRow->type);

		// Return if the class does not exist
		if (!class_exists($strClass))
		{
			static::log('Content element class "'.$strClass.'" (content element "'.$objRow->type.'") does not exist', __METHOD__, TL_ERROR);

			return '';
		}

		$objRow->typePrefix = 'ce_';

		/** @var ContentElement $objElement */
		if($strColumn === null) {
			$strColumn = 'main';
		}
		$objElement = new $strClass($objRow, $strColumn);
		$objElement->generate();

		if(empty($objElement->Template)) {
			return [];
		}

		$Data = $objElement->Template->getData();
		unset($Data['Template']);
		$Data['singleSRC'] = $objRow->singleSRC;
		$Data = Helper::toObj($Data, null, true);

		return $Data;
	}

	/**
	 * Get a page layout and return it as database result object
	 *
	 * @param PageModel $objPage
	 *
	 * @return LayoutModel
	 */
	protected function getPageLayout($objPage)
	{
		$blnMobile = ($objPage->mobileLayout && Environment::get('agent')->mobile);

		// Override the autodetected value
		if (Input::cookie('TL_VIEW') == 'mobile')
		{
			$blnMobile = true;
		}
		elseif (Input::cookie('TL_VIEW') == 'desktop')
		{
			$blnMobile = false;
		}

		$intId = ($blnMobile && $objPage->mobileLayout) ? $objPage->mobileLayout : $objPage->layout;
		$objLayout = LayoutModel::findByPk($intId);

		// Die if there is no layout
		if (null === $objLayout)
		{
			$this->log('Could not find layout ID "' . $intId . '"', __METHOD__, TL_ERROR);
			throw new NoLayoutSpecifiedException('No layout specified');
		}

		$objPage->hasJQuery = $objLayout->addJQuery;
		$objPage->hasMooTools = $objLayout->addMooTools;
		$objPage->isMobile = $blnMobile;
		
		return $objLayout;
	}

	/**
	 * Create a new template
	 *
	 * @param PageModel   $objPage
	 * @param LayoutModel $objLayout
	 */
	protected function createTemplate($objPage, $objLayout)
	{
		$this->Template = new FrontendTemplate($objPage->template);
		$this->Template->viewport = '';
		$this->Template->framework = '';

		$arrFramework = StringUtil::deserialize($objLayout->framework);

		// Generate the CSS framework
		if (\is_array($arrFramework) && \in_array('layout.css', $arrFramework))
		{
			$strFramework = '';

			if (\in_array('responsive.css', $arrFramework))
			{
				$this->Template->viewport = '<meta name="viewport" content="width=device-width,initial-scale=1.0">' . "\n";
			}

			// Wrapper
			if ($objLayout->static)
			{
				$arrSize = StringUtil::deserialize($objLayout->width);

				if (isset($arrSize['value']) && $arrSize['value'] != '' && $arrSize['value'] >= 0)
				{
					$arrMargin = array('left'=>'0 auto 0 0', 'center'=>'0 auto', 'right'=>'0 0 0 auto');
					$strFramework .= sprintf('#wrapper{width:%s;margin:%s}', $arrSize['value'] . $arrSize['unit'], $arrMargin[$objLayout->align]);
				}
			}

			// Header
			if ($objLayout->rows == '2rwh' || $objLayout->rows == '3rw')
			{
				$arrSize = StringUtil::deserialize($objLayout->headerHeight);

				if (isset($arrSize['value']) && $arrSize['value'] != '' && $arrSize['value'] >= 0)
				{
					$strFramework .= sprintf('#header{height:%s}', $arrSize['value'] . $arrSize['unit']);
				}
			}

			$strContainer = '';

			// Left column
			if ($objLayout->cols == '2cll' || $objLayout->cols == '3cl')
			{
				$arrSize = StringUtil::deserialize($objLayout->widthLeft);

				if (isset($arrSize['value']) && $arrSize['value'] != '' && $arrSize['value'] >= 0)
				{
					$strFramework .= sprintf('#left{width:%s;right:%s}', $arrSize['value'] . $arrSize['unit'], $arrSize['value'] . $arrSize['unit']);
					$strContainer .= sprintf('padding-left:%s;', $arrSize['value'] . $arrSize['unit']);
				}
			}

			// Right column
			if ($objLayout->cols == '2clr' || $objLayout->cols == '3cl')
			{
				$arrSize = StringUtil::deserialize($objLayout->widthRight);

				if (isset($arrSize['value']) && $arrSize['value'] != '' && $arrSize['value'] >= 0)
				{
					$strFramework .= sprintf('#right{width:%s}', $arrSize['value'] . $arrSize['unit']);
					$strContainer .= sprintf('padding-right:%s;', $arrSize['value'] . $arrSize['unit']);
				}
			}

			// Main column
			if ($strContainer != '')
			{
				$strFramework .= sprintf('#container{%s}', substr($strContainer, 0, -1));
			}

			// Footer
			if ($objLayout->rows == '2rwf' || $objLayout->rows == '3rw')
			{
				$arrSize = StringUtil::deserialize($objLayout->footerHeight);

				if (isset($arrSize['value']) && $arrSize['value'] != '' && $arrSize['value'] >= 0)
				{
					$strFramework .= sprintf('#footer{height:%s}', $arrSize['value'] . $arrSize['unit']);
				}
			}

			// Add the layout specific CSS
			if ($strFramework != '')
			{
				$this->Template->framework = Template::generateInlineStyle($strFramework) . "\n";
			}
		}

		// Overwrite the viewport tag (see #6251)
		if ($objLayout->viewport != '')
		{
			$this->Template->viewport = '<meta name="viewport" content="' . $objLayout->viewport . '">' . "\n";
		}

		$this->Template->mooScripts = '';

		// Make sure TL_JAVASCRIPT exists (see #4890)
		if (isset($GLOBALS['TL_JAVASCRIPT']) && \is_array($GLOBALS['TL_JAVASCRIPT']))
		{
			$arrAppendJs = $GLOBALS['TL_JAVASCRIPT'];
			$GLOBALS['TL_JAVASCRIPT'] = array();
		}
		else
		{
			$arrAppendJs = array();
			$GLOBALS['TL_JAVASCRIPT'] = array();
		}

		$container = System::getContainer();
		$rootDir = $container->getParameter('kernel.project_dir');

		// jQuery scripts
		if ($objLayout->addJQuery)
		{
			if ($objLayout->jSource == 'j_googleapis' || $objLayout->jSource == 'j_fallback')
			{
				try
				{
					/** @var AdapterInterface $cache */
					$cache = $container->get('cache.system');
					$hash = $cache->getItem('contao.jquery_hash');

					if (!$hash->isHit())
					{
						$hash->set('sha256-' . base64_encode(hash_file('sha256', $rootDir . '/assets/jquery/js/jquery.min.js', true)));
						$cache->save($hash);
					}

					$this->Template->mooScripts .= Template::generateScriptTag('https://code.jquery.com/jquery-' . PackageUtil::getNormalizedVersion('contao-components/jquery') . '.min.js', false, false, $hash->get(), 'anonymous') . "\n";

					// Local fallback (thanks to DyaGa)
					if ($objLayout->jSource == 'j_fallback')
					{
						$this->Template->mooScripts .= Template::generateInlineScript('window.jQuery || document.write(\'<script src="' . Controller::addAssetsUrlTo('assets/jquery/js/jquery.min.js') .'">\x3C/script>\')') . "\n";
					}
				}
				catch (\OutOfBoundsException $e)
				{
					$GLOBALS['TL_JAVASCRIPT'][] = 'assets/jquery/js/jquery.min.js|static';
				}
			}
			else
			{
				$GLOBALS['TL_JAVASCRIPT'][] = 'assets/jquery/js/jquery.min.js|static';
			}
		}

		// MooTools scripts
		if ($objLayout->addMooTools)
		{
			if ($objLayout->mooSource == 'moo_googleapis' || $objLayout->mooSource == 'moo_fallback')
			{
				try
				{
					$version = PackageUtil::getNormalizedVersion('contao-components/mootools');

					if (version_compare($version, '1.5.1', '>'))
					{
						$this->Template->mooScripts .= Template::generateScriptTag('https://ajax.googleapis.com/ajax/libs/mootools/' . $version . '/mootools.min.js', false, false, null, 'anonymous') . "\n";
					}
					else
					{
						$this->Template->mooScripts .= Template::generateScriptTag('https://ajax.googleapis.com/ajax/libs/mootools/' . $version . '/mootools-yui-compressed.js', false, false, null, 'anonymous') . "\n";
					}

					// Local fallback (thanks to DyaGa)
					if ($objLayout->mooSource == 'moo_fallback')
					{
						$this->Template->mooScripts .= Template::generateInlineScript('window.MooTools || document.write(\'<script src="' . Controller::addAssetsUrlTo('assets/mootools/js/mootools-core.min.js') . '">\x3C/script>\')') . "\n";
					}

					$GLOBALS['TL_JAVASCRIPT'][] = 'assets/mootools/js/mootools-more.min.js|static';
					$GLOBALS['TL_JAVASCRIPT'][] = 'assets/mootools/js/mootools-mobile.min.js|static';
				}
				catch (\OutOfBoundsException $e)
				{
					$GLOBALS['TL_JAVASCRIPT'][] = 'assets/mootools/js/mootools.min.js|static';
				}
			}
			else
			{
				$GLOBALS['TL_JAVASCRIPT'][] = 'assets/mootools/js/mootools.min.js|static';
			}
		}

		// Picturefill
		if ($objLayout->picturefill)
		{
			$GLOBALS['TL_JAVASCRIPT'][] = 'assets/respimage/js/respimage.min.js|static';
		}

		// Check whether TL_APPEND_JS exists (see #4890)
		if (!empty($arrAppendJs))
		{
			$GLOBALS['TL_JAVASCRIPT'] = array_merge($GLOBALS['TL_JAVASCRIPT'], $arrAppendJs);
		}

		// Initialize the sections
		$this->Template->articles = [
			'header' => [],
			'left' => [],
			'main' => [],
			'right' => [],
			'footer' => []
		];

		// Initialize the custom layout sections
		$this->Template->sections = array();
		$this->Template->positions = array();

		if ($objLayout->sections != '')
		{
			$arrPositions = array();
			$arrSections = StringUtil::deserialize($objLayout->sections);
			
			if (!empty($arrSections) && \is_array($arrSections))
			{
				foreach ($arrSections as $v)
				{
					$arrPositions[$v['position']][$v['id']] = $v;
				}
			}

			$this->Template->positions = $arrPositions;
		}

		// Default settings
		$this->Template->layout = $objLayout->row();
		$this->Template->language = $GLOBALS['TL_LANGUAGE'];
		$this->Template->charset = Config::get('characterSet');
		$this->Template->base = Environment::get('base');
		$this->Template->isRTL = false;
	}

	/**
	 * Create all header scripts
	 *
	 * @param PageModel   $objPage
	 * @param LayoutModel $objLayout
	 */
	protected function createHeaderScripts($objPage, $objLayout)
	{
		$strStyleSheets = '';
		$strCcStyleSheets = '';
		$arrStyleSheets = StringUtil::deserialize($objLayout->stylesheet);
		$arrFramework = StringUtil::deserialize($objLayout->framework);

		// Google web fonts
		if ($objLayout->webfonts != '')
		{
			$strStyleSheets .= Template::generateStyleTag('https://fonts.googleapis.com/css?family=' . str_replace('|', '%7C', $objLayout->webfonts), 'all') . "\n";
		}

		// Add the Contao CSS framework style sheets
		if (\is_array($arrFramework))
		{
			foreach ($arrFramework as $strFile)
			{
				if ($strFile != 'tinymce.css')
				{
					$GLOBALS['TL_FRAMEWORK_CSS'][] = 'assets/contao/css/' . basename($strFile, '.css') . '.min.css';
				}
			}
		}

		// Make sure TL_USER_CSS is set
		if (!\is_array($GLOBALS['TL_USER_CSS']))
		{
			$GLOBALS['TL_USER_CSS'] = array();
		}

		// User style sheets
		if (\is_array($arrStyleSheets) && \strlen($arrStyleSheets[0]))
		{
			$objStylesheets = StyleSheetModel::findByIds($arrStyleSheets);

			if ($objStylesheets !== null)
			{
				while ($objStylesheets->next())
				{
					$media = implode(',', StringUtil::deserialize($objStylesheets->media));

					// Overwrite the media type with a custom media query
					if ($objStylesheets->mediaQuery != '')
					{
						$media = $objStylesheets->mediaQuery;
					}

					// Style sheets with a CC or a combination of font-face and media-type != all cannot be aggregated (see #5216)
					if ($objStylesheets->cc || ($objStylesheets->hasFontFace && $media != 'all'))
					{
						$strStyleSheet = '';

						// External style sheet
						if ($objStylesheets->type == 'external')
						{
							$objFile = FilesModel::findByPk($objStylesheets->singleSRC);

							if ($objFile !== null)
							{
								$strStyleSheet = Template::generateStyleTag(Controller::addFilesUrlTo($objFile->path), $media, null);
							}
						}
						else
						{
							$strStyleSheet = Template::generateStyleTag(Controller::addAssetsUrlTo('assets/css/' . $objStylesheets->name . '.css'), $media, max($objStylesheets->tstamp, $objStylesheets->tstamp2, $objStylesheets->tstamp3));
						}

						if ($objStylesheets->cc)
						{
							$strStyleSheet = '<!--[' . $objStylesheets->cc . ']>' . $strStyleSheet . '<![endif]-->';
						}

						$strCcStyleSheets .= $strStyleSheet . "\n";
					}
					else
					{
						// External style sheet
						if ($objStylesheets->type == 'external')
						{
							$objFile = FilesModel::findByPk($objStylesheets->singleSRC);

							if ($objFile !== null)
							{
								$GLOBALS['TL_USER_CSS'][] = $objFile->path . '|' . $media . '|static';
							}
						}
						else
						{
							$GLOBALS['TL_USER_CSS'][] = 'assets/css/' . $objStylesheets->name . '.css|' . $media . '|static|' . max($objStylesheets->tstamp, $objStylesheets->tstamp2, $objStylesheets->tstamp3);
						}
					}
				}
			}
		}

		$arrExternal = StringUtil::deserialize($objLayout->external);

		// External style sheets
		if (!empty($arrExternal) && \is_array($arrExternal))
		{
			// Consider the sorting order (see #5038)
			if ($objLayout->orderExt != '')
			{
				$tmp = StringUtil::deserialize($objLayout->orderExt);

				if (!empty($tmp) && \is_array($tmp))
				{
					// Remove all values
					$arrOrder = array_map(function () {}, array_flip($tmp));

					// Move the matching elements to their position in $arrOrder
					foreach ($arrExternal as $k=>$v)
					{
						if (\array_key_exists($v, $arrOrder))
						{
							$arrOrder[$v] = $v;
							unset($arrExternal[$k]);
						}
					}

					// Append the left-over style sheets at the end
					if (!empty($arrExternal))
					{
						$arrOrder = array_merge($arrOrder, array_values($arrExternal));
					}

					// Remove empty (unreplaced) entries
					$arrExternal = array_values(array_filter($arrOrder));
					unset($arrOrder);
				}
			}

			// Get the file entries from the database
			$objFiles = FilesModel::findMultipleByUuids($arrExternal);
			$rootDir = System::getContainer()->getParameter('kernel.project_dir');

			if ($objFiles !== null)
			{
				$arrFiles = array();

				while ($objFiles->next())
				{
					if (file_exists($rootDir . '/' . $objFiles->path))
					{
						$arrFiles[] = $objFiles->path . '|static';
					}
				}

				// Inject the external style sheets before or after the internal ones (see #6937)
				if ($objLayout->loadingOrder == 'external_first')
				{
					array_splice($GLOBALS['TL_USER_CSS'], 0, 0, $arrFiles);
				}
				else
				{
					array_splice($GLOBALS['TL_USER_CSS'], \count($GLOBALS['TL_USER_CSS']), 0, $arrFiles);
				}
			}
		}

		// Add a placeholder for dynamic style sheets (see #4203)
		$strStyleSheets .= '[[TL_CSS]]';

		// Always add conditional style sheets at the end
		$strStyleSheets .= $strCcStyleSheets;

		// Add a placeholder for dynamic <head> tags (see #4203)
		$strHeadTags = '[[TL_HEAD]]';

		// Add the analytics scripts
		if ($objLayout->analytics != '')
		{
			$arrAnalytics = StringUtil::deserialize($objLayout->analytics, true);

			foreach ($arrAnalytics as $strTemplate)
			{
				if ($strTemplate != '')
				{
					$objTemplate = new FrontendTemplate($strTemplate);
					$strHeadTags .= $objTemplate->parse();
				}
			}
		}

		// Add the user <head> tags
		if ($strHead = trim($objLayout->head))
		{
			$strHeadTags .= $strHead . "\n";
		}

		$this->Template->stylesheets = $strStyleSheets;
		$this->Template->head = $strHeadTags;
	}

	/**
	 * Create all footer scripts
	 *
	 * @param LayoutModel $objLayout
	 */
	protected function createFooterScripts($objLayout)
	{
		$strScripts = '';

		// jQuery
		if ($objLayout->addJQuery)
		{
			$arrJquery = StringUtil::deserialize($objLayout->jquery, true);

			foreach ($arrJquery as $strTemplate)
			{
				if ($strTemplate != '')
				{
					$objTemplate = new FrontendTemplate($strTemplate);
					$strScripts .= $objTemplate->parse();
				}
			}

			// Add a placeholder for dynamic scripts (see #4203)
			$strScripts .= '[[TL_JQUERY]]';
		}

		// MooTools
		if ($objLayout->addMooTools)
		{
			$arrMootools = StringUtil::deserialize($objLayout->mootools, true);

			foreach ($arrMootools as $strTemplate)
			{
				if ($strTemplate != '')
				{
					$objTemplate = new FrontendTemplate($strTemplate);
					$strScripts .= $objTemplate->parse();
				}
			}

			// Add a placeholder for dynamic scripts (see #4203)
			$strScripts .= '[[TL_MOOTOOLS]]';
		}

		// Add the framework agnostic JavaScripts
		if ($objLayout->scripts != '')
		{
			$arrScripts = StringUtil::deserialize($objLayout->scripts, true);

			foreach ($arrScripts as $strTemplate)
			{
				if ($strTemplate != '')
				{
					$objTemplate = new FrontendTemplate($strTemplate);
					$strScripts .= $objTemplate->parse();
				}
			}
		}

		// Add a placeholder for dynamic scripts (see #4203, #5583)
		$strScripts .= '[[TL_BODY]]';

		// Add the external JavaScripts
		$arrExternalJs = StringUtil::deserialize($objLayout->externalJs);

		// External JavaScripts
		if (!empty($arrExternalJs) && \is_array($arrExternalJs))
		{
			// Consider the sorting order (see #5038)
			if ($objLayout->orderExtJs != '')
			{
				$tmp = StringUtil::deserialize($objLayout->orderExtJs);

				if (!empty($tmp) && \is_array($tmp))
				{
					// Remove all values
					$arrOrder = array_map(function () {}, array_flip($tmp));

					// Move the matching elements to their position in $arrOrder
					foreach ($arrExternalJs as $k=>$v)
					{
						if (\array_key_exists($v, $arrOrder))
						{
							$arrOrder[$v] = $v;
							unset($arrExternalJs[$k]);
						}
					}

					// Append the left-over JavaScripts at the end
					if (!empty($arrExternalJs))
					{
						$arrOrder = array_merge($arrOrder, array_values($arrExternalJs));
					}

					// Remove empty (unreplaced) entries
					$arrExternalJs = array_values(array_filter($arrOrder));
					unset($arrOrder);
				}
			}
		}

		// Get the file entries from the database
		$objFiles = FilesModel::findMultipleByUuids($arrExternalJs);
		$rootDir = System::getContainer()->getParameter('kernel.project_dir');

		if ($objFiles !== null)
		{
			while ($objFiles->next())
			{
				if (file_exists($rootDir . '/' . $objFiles->path))
				{
					$strScripts .= Template::generateScriptTag($objFiles->path, false, null);
				}
			}
		}

		// Add the custom JavaScript
		if ($objLayout->script != '')
		{
			$strScripts .= "\n" . trim($objLayout->script) . "\n";
		}

		$this->Template->mootools = $strScripts;
	}
}

class_alias(PageRegular::class, 'PageRegular');
