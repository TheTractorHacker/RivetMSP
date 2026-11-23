function showOTPViaCredentialID(credential_id) {
    // Send a GET request to ajax.php as ajax.php?get_totp_token_via_id=true&credential_id=ID
    jQuery.get(
        "../ajax.php", {
            get_totp_token_via_id: 'true',
            credential_id: credential_id
        },
        function(data) {
            //If we get a response from post.php, parse it as JSON
            const token = data; // jQuery already parsed the application/json response (review R1)
            if (token && typeof token === "object") { return; } // {error: ...}: show nothing

            document.getElementById("otp_" + credential_id).innerText = token

        }
    );
}
