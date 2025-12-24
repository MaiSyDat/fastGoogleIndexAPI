/**
 * Admin JavaScript for Fast Google Indexing API
 *
 * @package FastGoogleIndexing
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		
		// Toggle Auto-Scan Speed row visibility
		var toggle = document.getElementById('auto_scan_enabled');
		var speedRow = document.getElementById('scan_speed_row');
		if (toggle && speedRow) {
			toggle.addEventListener('change', function() {
				if (this.checked) {
					speedRow.classList.remove('fgi-hidden');
				} else {
					speedRow.classList.add('fgi-hidden');
				}
			});
		}

		// Handle meta box submit button with action type
		var submitBtn = document.getElementById('fast-google-indexing-submit-btn');
		if (submitBtn) {
			var actionTypeSelectId = submitBtn.getAttribute('data-action-type-select');
			var actionTypeSelect = actionTypeSelectId ? document.getElementById(actionTypeSelectId) : null;
			
			if (actionTypeSelect) {
				submitBtn.addEventListener('click', function(e) {
					var actionType = actionTypeSelect.value;
					var url = this.getAttribute('href');
					if (url && url.indexOf('action_type=') === -1) {
						url += '&action_type=' + encodeURIComponent(actionType);
						this.setAttribute('href', url);
					}
				});
			}
		}

		// Use event delegation for better performance with dynamic content.
		$(document).on('click', '.fgi-check-status-btn', function(e) {
			e.preventDefault();
			var btn = $(this);
			
			// Prevent multiple clicks.
			if (btn.prop('disabled')) {
				return;
			}
			
			var postId = btn.data('post-id');
			if (!postId) {
				return;
			}

			var originalText = btn.text();
			var row = btn.closest('tr');
			// Find status cell - try multiple selectors for different contexts
			var statusCell = row.find('td:has(.fgi-status-indexed), td:has(.fgi-status-not-indexed), td:has(.fgi-status-unknown), td.column-google_status').first();
			
			btn.prop('disabled', true).text(fgiAdmin.checkingText);
			
			$.ajax({
				url: fgiAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'fgi_check_status',
					nonce: fgiAdmin.checkStatusNonce,
					post_id: postId
				},
				timeout: 30000,
				success: function(response) {
					if (response.success) {
						var status = response.data.status;
						var message = response.data.message;
						
						// Update status icon with text
						var statusHtml = '';
						if (status === 'URL_IN_INDEX') {
							statusHtml = '<span class="fgi-status-indexed" title="' + fgiAdmin.indexedText + '"><span class="dashicons dashicons-yes-alt"></span> ' + fgiAdmin.indexedText + '</span>';
						} else if (status === 'URL_NOT_IN_INDEX') {
							statusHtml = '<span class="fgi-status-not-indexed" title="' + fgiAdmin.notIndexedText + '"><span class="dashicons dashicons-dismiss"></span> ' + fgiAdmin.notIndexedText + '</span>';
						} else {
							statusHtml = '<span class="fgi-status-unknown" title="Unknown"><span class="dashicons dashicons-minus"></span> Unknown</span>';
						}
						
						// Update status in table
						if (statusCell.length) {
							statusCell.html(statusHtml);
						}
						
						// Update last checked time if available
						if (response.data.last_checked) {
							var lastCheckedCell = row.find('td').eq(4); // Last Checked column
							if (lastCheckedCell.length) {
								var date = new Date(response.data.last_checked * 1000);
								var formattedDate = date.toLocaleString();
								lastCheckedCell.html(formattedDate);
							}
						}
						
						// If status changed from URL_IN_INDEX to URL_NOT_IN_INDEX, remove row (it will appear in Not Indexed tab)
						var currentStatus = row.find('.fgi-status-indexed').length > 0 ? 'URL_IN_INDEX' : 'unknown';
						if (currentStatus === 'URL_IN_INDEX' && status === 'URL_NOT_IN_INDEX') {
							setTimeout(function() {
								row.fadeOut(300, function() {
									$(this).remove();
								});
							}, 1500);
						}
						
						// Show success message (use CSS class)
						var msgDiv = $('<div class="notice notice-success inline fgi-notice"><p>' + message + '</p></div>');
						btn.after(msgDiv);
						setTimeout(function() {
							msgDiv.fadeOut(300, function() {
								$(this).remove();
							});
						}, 3000);
						
						// Update last checked time if available
						if (response.data.last_checked) {
							var lastCheckedCell = row.find('td').eq(4); // Last Checked column (5th column, 0-indexed)
							if (lastCheckedCell.length) {
								// Format date (you may need to adjust this based on your date format)
								var date = new Date(response.data.last_checked * 1000);
								var formattedDate = date.toLocaleString();
								lastCheckedCell.html(formattedDate);
							}
						}
						
						// Update button state
						btn.prop('disabled', false).text(fgiAdmin.checkStatusText || 'Check Status');
					} else {
						var errorMsg = response.data && response.data.message ? response.data.message : fgiAdmin.errorText;
						var errorDiv = $('<div class="notice notice-error inline fgi-notice"><p>' + errorMsg + '</p></div>');
						btn.after(errorDiv);
						setTimeout(function() {
							errorDiv.fadeOut(300, function() {
								$(this).remove();
							});
						}, 5000);
						btn.prop('disabled', false).text(originalText);
					}
				},
				error: function(xhr, status, error) {
					var errorMsg = fgiAdmin.requestFailedText;
					if (status === 'timeout') {
						errorMsg = fgiAdmin.timeoutText;
					}
					var errorDiv = $('<div class="notice notice-error inline fgi-notice"><p>' + errorMsg + '</p></div>');
					btn.after(errorDiv);
					setTimeout(function() {
						errorDiv.fadeOut(300, function() {
							$(this).remove();
						});
					}, 5000);
					btn.prop('disabled', false).text(originalText);
				}
			});
		});

		// Handle Index Now button (AJAX submit)
		$(document).on('click', '.fgi-submit-url-btn', function(e) {
			e.preventDefault();
			var btn = $(this);
			
			// Prevent multiple clicks
			if (btn.prop('disabled')) {
				return;
			}
			
			var postId = btn.data('post-id');
			var actionType = btn.data('action-type') || 'URL_UPDATED';
			if (!postId) {
				return;
			}

			var originalText = btn.text();
			var row = btn.closest('tr');
			var statusCell = row.find('td:has(.fgi-status-indexed), td:has(.fgi-status-not-indexed), td:has(.fgi-status-unknown)').first();
			var lastCheckedCell = row.find('td').eq(4); // Last Checked column
			
			btn.prop('disabled', true).text(fgiAdmin.submittingText);
			
			$.ajax({
				url: fgiAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'fgi_submit_url',
					nonce: fgiAdmin.submitUrlNonce,
					post_id: postId,
					action_type: actionType
				},
				timeout: 30000,
				success: function(response) {
					if (response.success) {
						var status = response.data.status;
						var lastChecked = response.data.last_checked;
						var message = response.data.message;
						
						// Update status icon
						var statusHtml = '';
						if (status === 'URL_IN_INDEX') {
							statusHtml = '<span class="fgi-status-indexed" title="' + fgiAdmin.indexedText + '"><span class="dashicons dashicons-yes-alt"></span> ' + fgiAdmin.indexedText + '</span>';
						} else {
							statusHtml = '<span class="fgi-status-unknown" title="' + fgiAdmin.notIndexedText + '"><span class="dashicons dashicons-minus"></span> Unknown</span>';
						}
						
						// Update status cell
						if (statusCell.length) {
							statusCell.html(statusHtml);
						}
						
						// Update last checked time
						if (lastChecked && lastCheckedCell.length) {
							var date = new Date(lastChecked * 1000);
							var formattedDate = date.toLocaleString();
							lastCheckedCell.html(formattedDate);
						}
						
						// Show success message
						var msgDiv = $('<div class="notice notice-success inline fgi-notice"><p>' + message + '</p></div>');
						btn.after(msgDiv);
						setTimeout(function() {
							msgDiv.fadeOut(300, function() {
								$(this).remove();
							});
						}, 3000);
						
						// If status is URL_IN_INDEX, remove row (it will appear in Indexed tab)
						if (status === 'URL_IN_INDEX') {
							setTimeout(function() {
								row.fadeOut(300, function() {
									$(this).remove();
								});
							}, 1500);
						}
						
						btn.prop('disabled', false).text(originalText);
					} else {
						var errorMsg = response.data && response.data.message ? response.data.message : fgiAdmin.errorText;
						var errorDiv = $('<div class="notice notice-error inline fgi-notice"><p>' + errorMsg + '</p></div>');
						btn.after(errorDiv);
						setTimeout(function() {
							errorDiv.fadeOut(300, function() {
								$(this).remove();
							});
						}, 5000);
						btn.prop('disabled', false).text(originalText);
					}
				},
				error: function(xhr, status, error) {
					var errorMsg = fgiAdmin.requestFailedText;
					if (status === 'timeout') {
						errorMsg = fgiAdmin.timeoutText;
					}
					var errorDiv = $('<div class="notice notice-error inline fgi-notice"><p>' + errorMsg + '</p></div>');
					btn.after(errorDiv);
					setTimeout(function() {
						errorDiv.fadeOut(300, function() {
							$(this).remove();
						});
					}, 5000);
					btn.prop('disabled', false).text(originalText);
				}
			});
		});
	});

})(jQuery);

