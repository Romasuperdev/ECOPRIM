/**
 * Cadre commun à tous les blocs : titre, actions facultatives, et surtout un état
 * vide explicite. Un graphique sans données doit le dire — un cadre vide laisse
 * croire à une panne, alors que la vraie réponse est « rien n'a encore été saisi ».
 */
/**
 * @param {boolean} hauteurFixe  Les graphiques recharts (`ResponsiveContainer`) exigent
 *   un conteneur de hauteur définie. Les autres blocs, eux, doivent pouvoir grandir :
 *   sur mobile l'anneau passe au-dessus de sa légende et déborderait d'une hauteur figée.
 */
export function CarteGraphique({ titre, actions, vide, messageVide, hauteur = 260, hauteurFixe = false, children }) {
  return (
    <div className="card flex flex-col rounded-2xl p-5">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-base font-bold text-heading">{titre}</h2>
        {actions}
      </div>
      {vide ? (
        <div
          className="flex flex-1 items-center justify-center rounded-xl border border-dashed px-4 text-center text-sm font-medium text-muted"
          style={{ minHeight: hauteur }}
        >
          {messageVide ?? 'Pas encore de données pour cette année.'}
        </div>
      ) : (
        <div style={hauteurFixe ? { height: hauteur } : { minHeight: hauteur }}>{children}</div>
      )}
    </div>
  )
}
