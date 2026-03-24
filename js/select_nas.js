document.addEventListener("DOMContentLoaded", function () {

    console.log("JS NAS chargé");

    const select = document.getElementById('nasSelect');

    fetch('../api/nas.php')
        .then(res => res.json())
        .then(response => {

            console.log("DATA:", response);

            if (!response.success) {
                console.error(response.message);
                return;
            }

            select.innerHTML = '<option value="">-- Choisir un serveur --</option>';

            response.data.forEach(nas => {

                const option = document.createElement('option');
                option.value = nas.id;
                option.textContent = nas.shortname;

                select.appendChild(option);
            });

        })
        .catch(err => {
            console.error("Erreur fetch:", err);
        });

});