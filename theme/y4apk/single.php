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


<style>
.unlock-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.55);
  display:flex;justify-content:center;align-items:center;
  z-index:999999;animation:f .25s forwards;opacity:0
}
@keyframes f{to{opacity:1}}

.unlock-popup{
  background:#fff;border-radius:14px;
  padding:16px;width:90%;max-width:300px;
  text-align:center;color:#222;font-family:Arial;
  animation:p .25s forwards;transform:scale(.92);opacity:0;
  box-shadow:0 8px 25px rgba(0,0,0,.12)
}
@keyframes p{to{transform:scale(1);opacity:1}}

.unlock-popup h3{
  font-size:16px;margin-bottom:4px;color:#111
}
.unlock-popup p{
  font-size:12px;color:#666;margin-bottom:6px
}

.ad-card{
  background:#f9fbff;border-radius:10px;
  padding:10px;display:flex;gap:8px;
  align-items:center;cursor:pointer;
  margin-top:8px;border:1px solid #e3e8f0;
  transition:.2s
}
.ad-card:hover{transform:translateY(-1px);background:#f1f6ff}

.ad-thumb img{
  width:50px;height:50px;border-radius:8px;
  object-fit:cover
}

.ad-title{
  font-size:13px;font-weight:600;color:#111
}
.ad-desc{
  font-size:11px;color:#666
}

/* Progress */
.progress-bar{
  width:100%;height:5px;background:#e5eaf1;
  border-radius:5px;margin:10px 0;overflow:hidden
}
.progress{
  height:100%;width:0;
  background:linear-gradient(90deg,#4facfe,#007bff);
  transition:.3s
}

#progress-text{
  font-size:11px;color:#555
}

@media(max-width:360px){
  .unlock-popup{padding:14px;max-width:260px}
  .ad-thumb img{width:45px;height:45px}
}
</style>

<div class="unlock-overlay" id="unlockOverlay">
  <div class="unlock-popup">
    <h3>Unlock Content</h3>
    <p>Tap the ad below to continue</p>

    <div class="ad-card" id="adClick">
      <div class="ad-thumb">
        <img src="https://y4apk.com/wp-content/uploads/2026/04/Ads.png">
      </div>
      <div>
        <div class="ad-title">Continue to Access</div>
        <div class="ad-desc">Fast & secure access</div>
      </div>
    </div>

    <div class="progress-bar">
      <div class="progress" id="p"></div>
    </div>
    <div id="progress-text">Progress 0/1</div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded",()=>{
  let o=document.getElementById("unlockOverlay"),
      a=document.getElementById("adClick"),
      p=document.getElementById("p"),
      t=document.getElementById("progress-text");

  if(sessionStorage.getItem("unlockDone")) return o.style.display="none";

  let c=0;
  a.onclick=()=>{
    if(c)return;c=1;
    window.open("https://y4apk.com/update-ads","_blank");
    p.style.width="100%";
    let i=3;
    t.innerText=`Progress 1/1 (${i}s...)`;
    let s=setInterval(()=>{
      i--;t.innerText=`Progress 1/1 (${i}s...)`;
      if(i<=0){
        clearInterval(s);
        t.innerText="✔ Done!";
        sessionStorage.setItem("unlockDone","1");
        o.style.display="none";
      }
    },1000);
  }
});
</script>


<?php get_footer(); ?>