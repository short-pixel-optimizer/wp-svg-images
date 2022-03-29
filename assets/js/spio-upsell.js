jQuery(document).ready(function($){
	$('.shortpixel-offer a').on('click', function(e){
		e.preventDefault();
		var $button = $(this);
		var href = $button.attr('href');
		var $wrapper = $button.closest('[data-plugin]');
		var button_alt_text = $button.attr('data-alt-text');

		if( button_alt_text ){
			$button.text( button_alt_text );
		}

		$.get( href, function( data ){
			$button.addClass('hidden');
			if( $button.hasClass('upsell-installer') ){
				$wrapper.find('.upsell-activate').removeClass('hidden');
			}else{
				$wrapper.find('.upsell-activate-done').removeClass('hidden');
			}
		});
	});
});