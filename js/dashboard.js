// dashboard.js

// Variable globale pour le graphique de bande passante
let bandwidthChart;

document.addEventListener('DOMContentLoaded', function () {
    // Zone pour afficher les messages d'erreur de l'API
    const messageArea = document.getElementById('messageArea');

    function fetchDashboardData() {
        // showSpinner(); // Décommenter si vous réintégrez le spinner plus tard
        fetch('api/get_stats.php') // L'URL de votre script PHP côté serveur
            .then(response => {
                if (!response.ok) {
                    // Si la réponse n'est pas un succès HTTP (ex: 404, 500)
                    throw new Error(`Erreur HTTP ! Statut: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                // hideSpinner(); // Décommenter si vous réintégrez le spinner plus tard

                // Afficher les erreurs spécifiques retournées par le script PHP
                if (data.error) {
                    console.error('Erreur API:', data.error);
                    if (messageArea) {
                        messageArea.innerHTML = `<div class="alert alert-danger" role="alert">${data.error}</div>`;
                        messageArea.style.display = 'block';
                        setTimeout(() => {
                            messageArea.style.display = 'none';
                        }, 5000); // Cache le message après 5 secondes
                    }
                    return; // Arrêter le traitement si une erreur API est présente
                }

                // Masquer les messages d'erreur s'il y en avait et que la requête est un succès
                if (messageArea) {
                    messageArea.style.display = 'none';
                }

                // --- Mettre à jour les informations du tableau de bord avec les données réelles ---

                // Mise à jour "Hotspot Actif"
                const activeHotspotUsersCount = document.getElementById('activeHotspotUsersCount');
                if (activeHotspotUsersCount) {
                    activeHotspotUsersCount.innerText = data.active_hotspot_users || '0';
                }

                // Mise à jour "Infos Système"
                const cpuLoad = document.getElementById('cpuLoad');
                const freeMemory = document.getElementById('freeMemory');
                const freeHdd = document.getElementById('freeHdd');
                if (cpuLoad) cpuLoad.innerText = data.cpu_load || '--';
                if (freeMemory) freeMemory.innerText = `${data.free_memory || '--'} / ${data.total_memory || '--'}`;
                if (freeHdd) freeHdd.innerText = `${data.free_hdd_space || '--'} / ${data.total_hdd_space || '--'}`;

                // Mise à jour "Bande Passante" (texte descriptif)
                const bandwidthAdditionalInfo = document.getElementById('bandwidthAdditionalInfo');
                if (bandwidthAdditionalInfo) {
                    bandwidthAdditionalInfo.innerText = `Vitesse actuelle : ${data.current_upload_speed || '--'} Mbps (Montant) / ${data.current_download_speed || '--'} Mbps (Descendant)`;
                }

                // Dessiner la courbe de bande passante
                if (data.bandwidth_history) {
                    drawBandwidthChart(data.bandwidth_history);
                } else {
                    console.warn("Les données d'historique de bande passante sont manquantes.");
                }

            })
            .catch(error => {
                // hideSpinner(); // Décommenter si vous réintégrez le spinner plus tard
                console.error('Erreur lors de la récupération des données du tableau de bord:', error);
                if (messageArea) {
                    messageArea.innerHTML = `<div class="alert alert-danger" role="alert">Erreur lors du chargement des données: ${error.message}. Vérifiez la console pour plus de détails.</div>`;
                    messageArea.style.display = 'block';
                    setTimeout(() => {
                        messageArea.style.display = 'none';
                    }, 5000); // Cache le message après 5 secondes
                }
            });
    }

    // Fonction pour dessiner/mettre à jour le graphique de bande passante
    function drawBandwidthChart(historyData) {
        const ctx = document.getElementById('bandwidthChart').getContext('2d');

        // Détruire l'ancien graphique s'il existe pour éviter les superpositions
        if (bandwidthChart) {
            bandwidthChart.destroy();
        }

        bandwidthChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: historyData.labels,
                datasets: [
                    {
                        label: 'Téléchargement (Mbps)',
                        data: historyData.download,
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Mise en ligne (Mbps)',
                        data: historyData.upload,
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        fill: true,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Permet au graphique de prendre toute la hauteur du conteneur
                plugins: {
                    legend: {
                        labels: {
                            color: 'white' // Couleur du texte des légendes
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)' // Couleur des labels de l'axe X
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)' // Couleur des grilles de l'axe X
                        }
                    },
                    y: {
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)' // Couleur des labels de l'axe Y
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)' // Couleur des grilles de l'axe Y
                        },
                        beginAtZero: true
                    }
                }
            }
        });
    }

    // Appeler la fonction au chargement de la page
    fetchDashboardData();
    // Rafraîchir les données toutes les 10 secondes (ou plus selon vos besoins)
    setInterval(fetchDashboardData, 10000); // Toutes les 10 secondes

    // --- Fonction redirectTo (si vous l'utilisez pour la navigation) ---
    // Si cette fonction est appelée ailleurs pour la navigation, assurez-vous qu'elle est bien définie.
    // Si elle n'est pas utilisée, vous pouvez la supprimer.
    // function redirectTo(pageUrl) {
    //     window.location.href = pageUrl;
    // }

}); // Fin de DOMContentLoaded