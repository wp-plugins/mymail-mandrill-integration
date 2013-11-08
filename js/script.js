jQuery(document).ready(function($) {
								
								
	$('.mymail-mandrill-api').on('change', function(){
		
		($(this).val() == 'smtp')
			? $('.mandrill-tab-smtp').slideDown()
			: $('.mandrill-tab-smtp').slideUp();
		
	});
	
});
