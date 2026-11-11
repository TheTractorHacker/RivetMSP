// Used to populate dynamic content in recurring_ticket_add_modal and ticket_add_modal_v2 based on selected client
(function () {

// Client selected listener
//  We seem to have to use jQuery to listen for events, as the client input is a select2 component?

const clientSelectDropdown = document.getElementById("changeClientSelect"); // Define client selector

// // If the client selector is disabled, we must be on a client-specific page instead. Trigger the lists to update.
if (clientSelectDropdown && clientSelectDropdown.disabled) {

    let client_id = $(clientSelectDropdown).find(':selected').val();

    populateLists(client_id);
}

// Listener for client selection. Populate select lists when a client is selected
// Native 'change': the .select2 class is backed by TomSelect (js/app.js initSelect2Widgets), which fires a native change event, not select2:select.
$(clientSelectDropdown).on('change', function (e) {
    let client_id = $(this).val();

    // Update the contacts dropdown list
    populateLists(client_id);

});

// Populates dropdowns with dynamic content based on the client ID
//  Called the client select dropdown is used or if the client select is disabled
function populateLists(client_id) {

    if (!client_id) { return; }

    populateContactsDropdown(client_id);

    populateAssetsDropdown(client_id);

    populateLocationsDropdown(client_id);

    populateVendorsDropdown(client_id);
}

// Repopulates a dynamic <select>. TomSelect renders from its own option list, so changing the raw <select> options is invisible
// once it has wrapped the element - go through its API when present.
function refreshDynamicDropdown(selectEl, placeholderText, items) {
    if (!selectEl) return;
    if (selectEl.tomselect) {
        const ts = selectEl.tomselect;
        ts.clearOptions();
        ts.addOption({ value: '0', text: placeholderText });
        items.forEach(item => ts.addOption(item));
        ts.refreshOptions(false);
        ts.setValue('0', true);
        return;
    }
    let i, L = selectEl.options.length - 1;
    for (i = L; i >= 0; i--) { selectEl.remove(i); }
    selectEl[selectEl.length] = new Option(placeholderText, '0');
    items.forEach(item => { selectEl[selectEl.length] = new Option(item.text, item.value); });
}

// Populate client contacts
function populateContactsDropdown(client_id) {
    // Send a GET request to ajax.php as ajax.php?get_client_contacts=true&client_id=NUM
    jQuery.get(
        "ajax.php",
        {get_client_contacts: 'true', client_id: client_id},
        function(data) {

            // If we get a response from ajax.php, parse it as JSON
            const response = JSON.parse(data);

            // Access the data for contacts (multiple)
            const contacts = response.contacts;

            // Contacts dropdown
            const contactSelectDropdown = document.getElementById("contactSelect");

            refreshDynamicDropdown(contactSelectDropdown, '- Contact -', contacts.map(contact => {
                let appendText = "";
                if (contact.contact_primary == "1") { appendText = " (Primary)"; }
                else if (contact.contact_technical == "1") { appendText = " (Technical)"; }
                return { value: contact.contact_id, text: contact.contact_name + appendText };
            }));

        }
    );
}

// Populate client assets
function populateAssetsDropdown(client_id) {
    jQuery.get(
        "ajax.php",
        {get_client_assets: 'true', client_id: client_id},
        function(data) {

            // If we get a response from ajax.php, parse it as JSON
            const response = JSON.parse(data);

            // Access the data for assets (multiple)
            const assets = response.assets;

            // Assets dropdown
            const assetSelectDropdown = document.getElementById("assetSelect");

            refreshDynamicDropdown(assetSelectDropdown, '- Asset -', assets.map(asset => ({
                value: asset.asset_id,
                text: asset.contact_name !== null ? asset.asset_name + " - " + asset.contact_name : asset.asset_name
            })));

        }
    );
}

// Populate client locations
function populateLocationsDropdown(client_id) {
    jQuery.get(
        "ajax.php",
        {get_client_locations: 'true', client_id: client_id},
        function(data) {

            // If we get a response from ajax.php, parse it as JSON
            const response = JSON.parse(data);

            // Access the data for locations (multiple)
            const locations = response.locations;

            // Locations dropdown
            const locationSelectDropdown = document.getElementById("locationSelect");

            refreshDynamicDropdown(locationSelectDropdown, '- Location -', locations.map(l => ({ value: l.location_id, text: l.location_name })));

        }
    );
}

// Populate client vendors
function populateVendorsDropdown(client_id) {
    jQuery.get(
        "ajax.php",
        {get_client_vendors: 'true', client_id: client_id},
        function(data) {

            // If we get a response from ajax.php, parse it as JSON
            const response = JSON.parse(data);

            // Access the data for locations (multiple)
            const vendors = response.vendors;

            // Locations dropdown
            const vendorSelectDropdown = document.getElementById("vendorSelect");

            refreshDynamicDropdown(vendorSelectDropdown, '- Vendor -', vendors.map(v => ({ value: v.vendor_id, text: v.vendor_name })));

        }
    );
}
})();
