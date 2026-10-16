<?php get_header(); ?>
<div class="wrp fullview-content">
  <article class="section f-app">
    <div class="block f-app-main">
		<?php 
		get_template_part('components/single/single-breadcrumb'); 
		get_template_part('components/single/single-thumbnail');
		get_template_part('components/single/single-description');
		?>
    </div>
	  <?php 
	  get_template_part('components/single/single-meta');
	  get_template_part('components/single/single-download-links');
	  get_template_part('components/single/single-info');
	  ?>
  </article>
  <?php get_template_part('components/single/single-related-posts'); ?>
</div>
<?php get_template_part('template/footer'); ?>
<div id="ui-dialog"
  class="ui-dialog ui-widget ui-widget-content ui-corner-all modalfixed dle-popup-alert ui-draggable ui-dialog-buttons"
  tabindex="-1"
  style="display: none; outline: 0px; z-index: 1001; height: auto; min-width: 300px; width: auto; top: 50%; left: 50%; max-height: 211px; max-width: 470px; position: fixed; transform: translate(-50%, -50%)"
  role="dialog" aria-labelledby="ui-id-2">
  <div class="ui-dialog-titlebar ui-widget-header ui-corner-all ui-helper-clearfix">
    <span id="ui-id-2" class="ui-dialog-title"><?php esc_html_e('Information', 'y4apk'); ?></span>
    <a href="javascript:void(0);" class="ui-dialog-titlebar-close ui-corner-all" role="button">
      <span class="ui-icon ui-icon-closethick"><?php esc_html_e('close', 'y4apk'); ?></span>
    </a>
  </div>
  <div id="dlepopup" class="dle-alert ui-dialog-content ui-widget-content"
    style="width: auto; min-height: 0px; height: 64px; overflow: auto;" scrolltop="0" scrollleft="0">

  </div>
  <div class="ui-dialog-buttonpane ui-widget-content ui-helper-clearfix">
    <div class="ui-dialog-buttonset">
      <button type="button" class="ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only" role="button"
        aria-disabled="false">
        <span class="ui-button-text"><?php esc_html_e('Ok', 'y4apk'); ?></span>
      </button>
    </div>
  </div>
</div>

<?php get_footer(); ?>