jQuery(document).ready(function ($) {
    // Track explicitly selected variations
    let explicitlySelectedVariations = {};
    
    // Initialize explicitlySelectedVariations for each variable product
    $('.fbt-product').each(function() {
        const productId = $(this).find('.fbt-variations').data('product_id');
        if (productId) {
            explicitlySelectedVariations[productId] = false; // Default to false (not explicitly selected)
        }
    });

    // Combined function to update both price and button state
    function updateFBTState() {
        let total = 0;
        let allSelected = $('.fbt-checkbox:checked').length > 0;
        let allVariationsValid = true;
        let allVariationsExplicitlySelected = true;

        $('.fbt-checkbox:checked').each(function () {
            const productId = $(this).data('product_id');
            const $variations = $('.fbt-variations[data-product_id="' + productId + '"] select.fbt-variation');
            
            // For variable products
            if ($variations.length > 0) {
                const selectedAttributes = {};
                let allVariationsSelected = true;
                
                $variations.each(function () {
                    const attrValue = $(this).val();
                    if (!attrValue || attrValue.trim() === '') {
                        allVariationsSelected = false;
                        return false; // Break loop
                    }
                    selectedAttributes['attribute_' + $(this).data('attribute')] = attrValue;
                });
                
                if (!allVariationsSelected) {
                    allVariationsValid = false;
                    return false; // Break outer loop
                }

                // Check if user explicitly selected any variation for this product
                if (!explicitlySelectedVariations[productId]) {
                    allVariationsExplicitlySelected = false;
                    return false; // Break outer loop
                }

                // Find matching variation price
                try {
                    const variationData = JSON.parse($('.fbt-variation-data-' + productId).text());
                    const matchingVariation = variationData.find(variation => 
                        Object.keys(selectedAttributes).every(key => 
                            variation.attributes[key] === selectedAttributes[key]
                        )
                    );
                    
                    if (matchingVariation?.price > 0) {
                        total += parseFloat(matchingVariation.price);
                    }
                } catch (e) {
                    console.error('Error parsing variation data:', e);
                    allVariationsValid = false;
                }
            } else {
                // For simple products
                total += parseFloat($(this).data('price')) || 0;
            }
        });

        // Update UI
        $('#fbt-total-price').html('<span class="woocommerce-Price-amount amount">€' + total.toFixed(2) + '</span>');
        $('#fbt-heading').text(total >= 50 ? 
            'Frequently Bought Together (With Free Shipping)' : 
            'Frequently Bought Together');
        
        // Update button state - disabled unless all products have explicitly selected variations
        $('#add-all-to-cart').prop('disabled', !(allSelected && allVariationsValid && allVariationsExplicitlySelected));
    }

    // Handle product title updates when variations change
    function updateProductTitle($variation) {
        const $product = $variation.closest('.fbt-product');
        const $title = $product.find('p');
        
        if (!$product.data('original-title')) {
            $product.data('original-title', $title.text().split(' - ')[0]);
        }
        
        const variations = $product.find('.fbt-variation')
            .toArray()
            .map(d => d.options[d.selectedIndex].text)
            .filter(t => t && t !== 'Select');
            
        $title.text($product.data('original-title') + (variations.length ? ' - ' + variations.join(', ') : ''));
    }

    // Event handlers
    $(document)
        .on('change', '.fbt-checkbox', function() {
            updateFBTState();
        })
        .on('change', '.fbt-variation', function() {
            const productId = $(this).closest('.fbt-variations').data('product_id');
            // Mark as explicitly selected only when the user changes the variation
            if ($(this).val() && $(this).val() !== '') {
                explicitlySelectedVariations[productId] = true;
            } else {
                explicitlySelectedVariations[productId] = false;
            }
            updateFBTState();
            updateProductTitle($(this));
        })
        .on('click', '#add-all-to-cart', function(e) {
            e.preventDefault();
            const $button = $(this);
            
            if ($button.prop('disabled')) {
                // Show error message when button is disabled
                Toastify({
                    text: "Please select variations for all products before adding to cart",
                    duration: 3000,
                    gravity: "top",
                    position: "right",
                    backgroundColor: "red",
                    stopOnFocus: true,
                }).showToast();
                return;
            }

            const productData = $('.fbt-checkbox:checked').map(function() {
                const productId = $(this).data('product_id');
                const variations = {};
                
                $('.fbt-variations[data-product_id="' + productId + '"] select').each(function() {
                    variations[$(this).data('attribute')] = $(this).val();
                });
                
                return { product_id: productId, variations: variations };
            }).get();

            if (!productData.length) {
                Toastify({
                    text: "Please select at least one product.",
                    duration: 3000,
                    gravity: "top",
                    position: "right",
                    backgroundColor: "red",
                    stopOnFocus: true,
                }).showToast();
                return;
            }

            $button.prop('disabled', true).text('Adding...');
            
            $.ajax({
                type: 'POST',
                url: fbt_ajax.ajax_url,
                data: {
                    action: 'fbt_add_all_to_cart',
                    product_data: JSON.stringify(productData)
                },
                success: function(response) {
                    if (response.success) {
                        $(document.body).trigger('wc_fragment_refresh');
                        setTimeout(() => window.location.reload(), 2000);
                    } else {
                        if (response.data.type === 'variation_required') {
                            Toastify({
                                text: response.data.message,
                                duration: 3000,
                                gravity: "top",
                                position: "right",
                                backgroundColor: "red",
                                stopOnFocus: true,
                            }).showToast();
                        } else {
                            Toastify({
                                text: response.data.message || "Failed to add products to cart",
                                duration: 3000,
                                gravity: "top",
                                position: "right",
                                backgroundColor: "red",
                                stopOnFocus: true,
                            }).showToast();
                        }
                        $button.prop('disabled', false).text('Add All to Cart');
                    }
                },
                error: function() {
                    Toastify({
                        text: "An error occurred while adding products to cart",
                        duration: 3000,
                        gravity: "top",
                        position: "right",
                        backgroundColor: "red",
                        stopOnFocus: true,
                    }).showToast();
                    $button.prop('disabled', false).text('Add All to Cart');
                }
            });
        });

    // Modal handling
    $('.select-variation-btn').click(function(e) {
        e.preventDefault();
        $('#' + $(this).data('modal-id')).show();
    });
    
    $('.close-modal-cross, .close-modal-button').click(function() {
        $(this).closest('.fbt-modal').hide();
    });
    
    $(window).click(function(e) {
        if ($(e.target).hasClass('fbt-modal')) {
            $(e.target).hide();
        }
    });

    // Initialize - Do not mark pre-selected variations as explicitly selected
    $('.fbt-variation').each(function() {
        const productId = $(this).closest('.fbt-variations').data('product_id');
        explicitlySelectedVariations[productId] = false; // Ensure not marked as selected
        if ($(this).val() && $(this).val() !== '') {
            updateProductTitle($(this)); // Update title if variation is pre-selected
        }
    });
    updateFBTState();
});