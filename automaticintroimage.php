<?php
/**
 * @copyright  Copyright (c) 2022- Steven Trooster. All rights reserved.
 *             Based on a plugin created by Mattia Verga.
 * @license    GNU General Public License version 3, or later
 * @Joomla     For Joomla 3.10 and Joomla 4
 */
// no direct access
defined("_JEXEC") or die();

use Joomla\CMS\Factory;

class plgContentAutomaticIntroImage extends JPlugin
{
    /**
     * Load the language file on instantiation. Note this is only available in Joomla 3.1 and higher.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    /**
        * Automatic creation of resized intro image from article full image
        *
        * @param   string   $context  The context of the content being passed to the
        plugin.
        * @param   mixed    $article  The JTableContent object that is
        being saved which holds the article data.
        * @param   boolean  $isNew    A boolean which is set to true if the content
        is about to be created.
        *
        * @return  boolean	True on success.
        */
    public function onContentBeforeSave($context, $article, $isNew)
    {
        // Check if we're saving an article
        $allowed_contexts = ["com_content.article", "com_content.form"];

        if (!in_array($context, $allowed_contexts)) {
            return true;
        }

        $images = json_decode($article->images);

        // Check ImageMagick
        if (!extension_loaded("imagick")) {
            Factory::getApplication()->enqueueMessage(
                JText::_(
                    "PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_IMAGICK_ERROR"
                ),
                "error"
            );
            return true;
        }

        // Return if intro image is already set
        if (isset($images->image_intro) and !empty($images->image_intro)) {
            Factory::getApplication()->enqueueMessage(
                JText::_("PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_ALREADY_SET"),
                "notice"
            );
            return true;
        }

        if ($this->params->get("UseFirstImage") == 1) {
            $dom = new DOMDocument();
            if ($article->introtext === ""):
                return true;
            endif;
            $dom->loadHTML($article->introtext);
            $all_images = $dom->getElementsByTagName("img");
            if (count($all_images) > 0) {
                $src_img = $all_images->item(0)->getAttribute("src");
                $src_alt = $all_images->item(0)->getAttribute("alt");
                $src_caption = "";
            } else {
                return true;
            }
        } else {
            if (
                !isset($images->image_fulltext) or
                empty($images->image_fulltext)
            ) {
                return true;
            }
            $src_img = $images->image_fulltext;
        }

        $width = (int) $this->params->get("Width");
        $height = (int) $this->params->get("Height");
        $compression_level = (int) $this->params->get("ImageQuality");

        // Check plugin settings
        if (
            $compression_level < 50 or
            $compression_level > 100 or
            $width < 10 or
            $width > 2000 or
            $height < 10 or
            $height > 2000
        ) {
            Factory::getApplication()->enqueueMessage(
                JText::_(
                    "PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_SETTINGS_ERROR"
                ),
                "error"
            );
            return true;
        }

        // Create resized image
        $thumb = new Imagick(JPATH_ROOT . "/" . $src_img);

        $thumb->scaleImage(
            $this->params->get("Crop") ? 0 : $width,
            $height,
            $this->params->get("MaintainAspectRatio")
        );

        if ($this->params->get("ChangeImageQuality") == 1) {
            $thumb->setImageCompressionQuality($compression_level);
        }

        if ($this->params->get("SetProgressiveJPG") == 1) {
            $thumb->setInterlaceScheme(Imagick::INTERLACE_PLANE);
        }

        // Get real image dimensions if maintain aspect ratio was selected
        if ($this->params->get("MaintainAspectRatio") == 1) {
            $width = $thumb->getImageWidth();
            $height = $thumb->getImageHeight();
        } elseif ($this->params->get("Crop") == 1) {
            $thumb->cropImage(
                $width,
                $height,
                ($thumb->getImageWidth() - $width) / 2,
                0
            );
        }

        // Set image intro name
        // {width} and {height} placeholders are changed to values
        $suffix = $this->params->get("Suffix");
        if (
            strpos($suffix, "{width}") !== false or
            strpos($suffix, "{height}") !== false
        ) {
            $suffix = str_replace(
                ["{width}", "{height}"],
                [$width, $height],
                $suffix
            );
        }
        $extension_pos = strrpos($src_img, ".");
        $image_with_suffix =
            substr($src_img, 0, $extension_pos) .
            $suffix .
            substr($src_img, $extension_pos);

        // Put the image in an absolute directory if said to do so
        if ($this->params->get("AbsoluteDir") == 1) {
            // Check if the subdir already exists
            $thumb_dir = JPATH_ROOT . "/" . $this->params->get("AbsDirPath");
            if (!JFolder::exists($thumb_dir)) {
                JFolder::create($thumb_dir);
            }
            $subdir_pos = strrpos($image_with_suffix, "/");
            $thumb_savepath =
                $thumb_dir . substr($image_with_suffix, $subdir_pos);
            $images->image_intro =
                $this->params->get("AbsDirPath") .
                substr($image_with_suffix, $subdir_pos);
        }
        // Put the image in a subdir if set to do so
        elseif ($this->params->get("PutInSubdir") == 1) {
            $subdir_pos = strrpos($image_with_suffix, "/");
            $images->image_intro =
                substr($image_with_suffix, 0, $subdir_pos) .
                "/" .
                $this->params->get("Subdir") .
                substr($image_with_suffix, $subdir_pos);

            // Check if the subdir already exist or create it
            $img_subdir =
                JPATH_ROOT .
                "/" .
                substr(
                    $images->image_intro,
                    0,
                    strrpos($images->image_intro, "/")
                );
            if (!JFolder::exists($img_subdir)) {
                JFolder::create($img_subdir);
            }
            $thumb_savepath = JPATH_ROOT . "/" . $images->image_intro;
        } else {
            $thumb_savepath = JPATH_ROOT . "/" . $image_with_suffix;
            $images->image_intro = $image_with_suffix;
        }

        // Copy Alt and Title fields
        if (
            $this->params->get("CopyAltTitle") == 1 and
            ($src_alt != "" or $src_altcaption != "")
        ) {
            $images->image_intro_alt = $src_alt;
            $images->image_intro_caption = $src_caption;
        }

        // Write resized image if it doesn't exist
        // and set Joomla object values
        if (!file_exists($thumb_savepath)) {
            $thumb->writeImage($thumb_savepath);
            Factory::getApplication()->enqueueMessage(
                JText::sprintf(
                    "PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_CREATED",
                    $thumb_savepath
                ),
                "message"
            );
        } else {
            Factory::getApplication()->enqueueMessage(
                JText::sprintf(
                    "PLG_CONTENT_AUTOMATICINTROIMAGE_MESSAGE_EXIST",
                    $thumb_savepath
                ),
                "message"
            );
        }

        $article->images = json_encode($images);

        $thumb->destroy();

        return true;
    }
}
?>
