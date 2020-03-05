<?php

declare(strict_types=1);

namespace MauticPlugin\GrapesJsBuilderBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\EmojiHelper;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\PageBundle\Entity\Page;
use Symfony\Component\HttpFoundation\Response;

class GrapesJsController extends CommonController
{
    const OBJECT_TYPE = ['email', 'page'];

    /**
     * Activate the custom builder.
     *
     * @param string $objectType
     * @param int $objectId
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function builderAction($objectType, $objectId)
    {
        if (!in_array($objectType, self::OBJECT_TYPE)) {
            throw new \Exception('Object not authorized to load custom builder', Response::HTTP_CONFLICT);
        }

        /** @var \Mautic\EmailBundle\Model\EmailModel|\Mautic\PageBundle\Model\PageModel $model */
        $model      = $this->getModel($objectType);
        $aclToCheck = 'email:emails:';

        if ($objectType === 'page') {
            $aclToCheck = 'page:pages:';
        }

        //permission check
        if (strpos($objectId, 'new') !== false) {
            $isNew = true;

            if (!$this->get('mautic.security')->isGranted($aclToCheck.'create')) {
                return $this->accessDenied();
            }

            /** @var \Mautic\EmailBundle\Entity\Email|\Mautic\PageBundle\Entity\Page $entity */
            $entity = $model->getEntity();
            $entity->setSessionId($objectId);
        } else {
            /** @var \Mautic\EmailBundle\Entity\Email|\Mautic\PageBundle\Entity\Page $entity */
            $entity = $model->getEntity($objectId);
            $isNew  = false;

            if ($entity == null
                || !$this->get('mautic.security')->hasEntityAccess(
                    $aclToCheck.'viewown',
                    $aclToCheck.'viewother',
                    $entity->getCreatedBy()
                )
            ) {
                return $this->accessDenied();
            }
        }

        $slots        = [];
        $type         = 'html';
        $template     = InputHelper::clean($this->request->query->get('template'));
        $templateName = ':'.$template.':'.$objectType;
        $content      = $entity->getContent();

        // Check for MJML template
        $logicalName = $this->factory->getHelper('theme')->checkForTwigTemplate($templateName.'.mjml.twig');

        if ($logicalName === $templateName.'.mjml.twig') {
            $type = 'mjml';
        } else {
            $logicalName = $this->factory->getHelper('theme')->checkForTwigTemplate($templateName.'.html.twig');
            $slots       = $this->factory->getTheme($template)->getSlots($objectType);

            //merge any existing changes
            $newContent = $this->get('session')->get('mautic.'.$objectType.'builder.'.$objectId.'.content', []);

            if (is_array($newContent)) {
                $content = array_merge($content, $newContent);
                // Update the content for processSlots
                $entity->setContent($content);
            }

            if ($objectType === 'page') {
                $this->processPageSlots($slots, $entity);
            } else {
                $this->processEmailSlots($slots, $entity);
            }
        }

        // Replace short codes to emoji
        $content = EmojiHelper::toEmoji($content, 'short');

        $renderedTemplate =  $this->renderView(
            $logicalName,
            [
                'isNew'     => $isNew,
                'slots'     => $slots,
                'content'   => $content,
                $objectType => $entity,
                'template'  => $template,
                'basePath'  => $this->request->getBasePath(),
            ]
        );

        $renderedTemplateHtml = ($type === 'html') ? $renderedTemplate : '';
        $renderedTemplateMjml = ($type === 'mjml') ? $renderedTemplate : '';

        return $this->render(
            'GrapesJsBuilderBundle:'.ucfirst($objectType).':template.html.php',
            [
                'templateHtml' => $renderedTemplateHtml,
                'templateMjml' => $renderedTemplateMjml,
            ]
        );
    }

    /**
     * PreProcess email slots for public view.
     *
     * @param array $slots
     * @param Email $entity
     */
    private function processEmailSlots($slots, $entity)
    {
        /** @var \Mautic\CoreBundle\Templating\Helper\SlotsHelper $slotsHelper */
        $slotsHelper = $this->get('templating.helper.slots');
        $content     = $entity->getContent();

        //Set the slots
        foreach ($slots as $slot => $slotConfig) {
            //support previous format where email slots are not defined with config array
            if (is_numeric($slot)) {
                $slot       = $slotConfig;
                $slotConfig = [];
            }

            $value = isset($content[$slot]) ? $content[$slot] : '';
            $slotsHelper->set($slot, "<div data-slot=\"text\" id=\"slot-{$slot}\">{$value}</div>");
        }

        //add builder toolbar
        $slotsHelper->start('builder'); ?>
        <input type="hidden" id="builder_entity_id" value="<?php echo $entity->getSessionId(); ?>"/>
        <?php
        $slotsHelper->stop();
    }

    /**
     * PreProcess page slots for public view.
     *
     * @param array $slots
     * @param Page  $entity
     */
    private function processPageSlots($slots, $entity)
    {
        /** @var \Mautic\CoreBundle\Templating\Helper\AssetsHelper $assetsHelper */
        $assetsHelper = $this->get('templating.helper.assets');
        /** @var \Mautic\CoreBundle\Templating\Helper\SlotsHelper $slotsHelper */
        $slotsHelper = $this->get('templating.helper.slots');
        $formFactory = $this->get('form.factory');

        $slotsHelper->inBuilder(true);

        $content = $entity->getContent();

        foreach ($slots as $slot => $slotConfig) {
            // backward compatibility - if slotConfig array does not exist
            if (is_numeric($slot)) {
                $slot       = $slotConfig;
                $slotConfig = [];
            }

            // define default config if does not exist
            if (!isset($slotConfig['type'])) {
                $slotConfig['type'] = 'html';
            }

            if (!isset($slotConfig['placeholder'])) {
                $slotConfig['placeholder'] = 'mautic.page.builder.addcontent';
            }

            $value = isset($content[$slot]) ? $content[$slot] : '';

            if ($slotConfig['type'] == 'slideshow') {
                if (isset($content[$slot])) {
                    $options = json_decode($content[$slot], true);
                } else {
                    $options = [
                        'width'            => '100%',
                        'height'           => '250px',
                        'background_color' => 'transparent',
                        'arrow_navigation' => false,
                        'dot_navigation'   => true,
                        'interval'         => 5000,
                        'pause'            => 'hover',
                        'wrap'             => true,
                        'keyboard'         => true,
                    ];
                }

                // Create sample slides for first time or if all slides were deleted
                if (empty($options['slides'])) {
                    $options['slides'] = [
                        [
                            'order'            => 0,
                            'background-image' => $assetsHelper->getUrl('media/images/mautic_logo_lb200.png'),
                            'captionheader'    => 'Caption 1',
                        ],
                        [
                            'order'            => 1,
                            'background-image' => $assetsHelper->getUrl('media/images/mautic_logo_db200.png'),
                            'captionheader'    => 'Caption 2',
                        ],
                    ];
                }

                // Order slides
                usort(
                    $options['slides'],
                    function ($a, $b) {
                        return strcmp($a['order'], $b['order']);
                    }
                );

                $options['slot']   = $slot;
                $options['public'] = false;

                // create config form
                $options['configForm'] = $formFactory->createNamedBuilder(
                    null,
                    'slideshow_config',
                    [],
                    ['data' => $options]
                )->getForm()->createView();

                // create slide config forms
                foreach ($options['slides'] as $key => &$slide) {
                    $slide['key']  = $key;
                    $slide['slot'] = $slot;
                    $slide['form'] = $formFactory->createNamedBuilder(
                        null,
                        'slideshow_slide_config',
                        [],
                        ['data' => $slide]
                    )->getForm()->createView();
                }

                $renderingEngine = $this->get('templating');

                if (method_exists($renderingEngine, 'getEngine')) {
                    $renderingEngine->getEngine('MauticPageBundle:Page:Slots/slideshow.html.php');
                }
                $slotsHelper->set($slot, $renderingEngine->render('MauticPageBundle:Page:Slots/slideshow.html.php', $options));
            } else {
                $slotsHelper->set($slot, "<div data-slot=\"text\" id=\"slot-{$slot}\">{$value}</div>");
            }
        }

        $slotsHelper->start('builder'); ?>
        <input type="hidden" id="builder_entity_id" value="<?php echo $entity->getSessionId(); ?>"/>
        <?php
        $slotsHelper->stop();
    }
}