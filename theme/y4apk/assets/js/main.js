/* Y4APK theme scripts (jQuery). Until 2.21 this file also carried jQuery UI 1.13.1, which nothing used. */

/* Loading animation */

function ShowLoading(text) {
    $('body').append('<div id="loading-layer" style="display:none">' + text + '</div>');
    var left = ($(window).width() - $('#loading-layer').width()) / 2;
    var top = ($(window).height() - $('#loading-layer').height()) / 2;
    $('#loading-layer').css({
        left: left + 'px',
        top: top + 'px',
        position: 'fixed',
        zIndex: '99'
    });
    $('#loading-layer').fadeTo('slow', 0.6);
}

function HideLoading() {
    $('#loading-layer').fadeOut('slow', function() {
        $('#loading-layer').remove();
    });
}

/* Rating */
var star_rating = $('#rating-layer');

if (star_rating.length > 0) {
    var ls = localStorage.getItem('post_rating-' + star_rating.data('post_id'));
    if (ls) {
        star_rating.attr('data-rated', 'true');
    }
}

var is_rated = star_rating.attr('data-rated') === 'true';

if (is_rated) {
    disable_star_ratings();
} else {
    enable_star_ratings();
}

function disable_star_ratings() {
    var rating_items = star_rating.find('li a');
    rating_items.each(function () {
        $(this).css('pointer-events', 'none');
        $(this).css('opacity', '0.5');
    });
}

function enable_star_ratings() {
    var rating_items = star_rating.find('li a');
    rating_items.each(function () {
        $(this).css('pointer-events', 'auto');
        $(this).css('opacity', '1');
    });
}

function doRate(rating, post_id) {
	ShowLoading('Loading.Please, wait...');
    $.ajax({
        type: "POST",
        url: ajaxurl,
        dataType: "json",
        data: {
            action: "save_post_rating",
            post_id: post_id,
            rating: rating
        },
        success: function(response) {
            if (response.success) {
                // update the current rating
                $(".current-rating").css("width", response.data.new_rating * 20 + '%');
                $(".f-app-view-rate .fw-b").text(response.data.new_rating);
				$('#vote-num').text(response.data.new_votes);
				localStorage.setItem('post_rating-'+post_id, rating);
				disable_star_ratings();
            } else {
				$("#dlepopup").text(response.errorInfo);
				$("#ui-dialog").css("display", "block");
            }
        },
        error: function(xhr, textStatus, errorThrown) {
            $("#dlepopup").text(errorThrown);
			$("#ui-dialog").css("display", "block");
        },
		complete: function() {
            HideLoading();
        }
    });
}

// attach a click handler to the Ok button
$('.ui-dialog-buttonset button').click(function() {
    // hide the popup when the Ok button is clicked
    $('.ui-dialog').hide();
});

// attach a click handler to the close button
$('.ui-dialog-titlebar-close').click(function() {
	// hide the popup when the close button is clicked
	$('.ui-dialog').hide();
});