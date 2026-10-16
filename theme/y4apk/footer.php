</div>
<?php wp_footer(); ?>
<script>
  $(document).ready(function () { $('html').removeClass('load'); });
  $(function () {
    $('.dropdown-form').click(function (e) { e.stopPropagation(); });
  });

  $(".section-recommend").hover(function () { $(this).addClass('recommend-anim'); });


  $('.menu-btn, .menu-btn-close').on('click', function () {
    $('.header-menu').toggleClass('open');
    setTimeout(function () { $('html').toggleClass('mm'); }, 50);
    return false;
  });


  $('.scroll-up').click(function () { $('html, body').animate({ scrollTop: 0 }, 'fast'); return false; });

  var $root = $('html, body');
  $('a.anchor').click(function () {
    var href = $.attr(this, 'href');
    $root.animate({
      scrollTop: $(href).offset().top
    }, 500);
    return false;
  });



  $('.block-keys-tabs').on('click', function () {
    $('.block-keys').toggleClass('select_u2'); return false;
  });
  $(function () {
    var tabContainers = $('.tab-content .tab-pane');
    tabContainers.hide().filter(':first').show();

    $('.tab-item').click(function () {
      tabContainers.hide();
      tabContainers.filter(this.hash).show();
      $('.tab-item').removeClass('active');
      $(this).addClass('active');
      return false;
    }).filter(':first').click();
  });

  function minWin(href) {
    var d = document.documentElement,
      h = 500,
      w = 500,
      myWindow = window.open(href, "myWindow", "scrollbars=1,height=" +
        Math.min(h, screen.availHeight) + ",width=" +
        Math.min(w, screen.availWidth) + ",left=" +
        Math.max(0, (d.clientWidth - w) / 2 + window.screenX) + ",top=" +
        Math.max(0, (d.clientHeight - h) / 2 + window.screenY)
      );
    if (myWindow.screenY >= screen.availHeight - myWindow.outerHeight) {
      myWindow.moveTo(myWindow.screenX, screen.availHeight - myWindow.outerHeight);
    }
    if (myWindow.screenX >= screen.availWidth - myWindow.outerWidth) {
      myWindow.moveTo(screen.availWidth - myWindow.outerWidth, myWindow.screenY);
    }
  }
</script>
</body>

</html>