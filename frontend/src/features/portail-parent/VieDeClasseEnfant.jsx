import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiVieClasseParent, fetchVieDeClasse } from './portailParentApi'
import { CarteAnnonce, CarteDevoir } from '../vie-classe/Cartes'

/*
 * Espace Parent, onglet Devoirs : les annonces récentes de la classe, les devoirs à faire
 * (avec la photo du tableau quand l'enseignant en a joint une), puis les devoirs passés.
 */
export default function VieDeClasseEnfant({ matricule }) {
  const [passesVisibles, setPassesVisibles] = useState(false)
  const { data, isLoading } = useQuery({
    queryKey: ['portail-vie-classe', matricule],
    queryFn: () => fetchVieDeClasse(matricule),
  })

  if (isLoading) return <p className="text-muted">Chargement…</p>

  const aujourdhui = new Date().toISOString().slice(0, 10)
  const devoirs = data?.devoirs ?? []
  // À faire : le plus proche d'abord ; passés : le plus récent d'abord.
  const aFaire = devoirs.filter((d) => !d.date_remise || d.date_remise >= aujourdhui)
    .sort((a, b) => String(a.date_remise).localeCompare(String(b.date_remise)))
  const passes = devoirs.filter((d) => d.date_remise && d.date_remise < aujourdhui)
  const annonces = (data?.annonces ?? []).slice(0, 5)

  return (
    <div className="space-y-6">
      {annonces.length > 0 && (
        <section>
          <h2 className="mb-3 text-base font-bold text-heading">Annonces de la classe</h2>
          <ul className="space-y-3">
            {annonces.map((a) => <CarteAnnonce key={a.id} annonce={a} chargerPhoto={apiVieClasseParent.photoAnnonce} />)}
          </ul>
        </section>
      )}

      <section>
        <h2 className="mb-3 text-base font-bold text-heading">À faire {aFaire.length > 0 && <span className="text-muted">({aFaire.length})</span>}</h2>
        {aFaire.length === 0 && <p className="card px-4 py-8 text-center text-sm text-muted">Aucun devoir à faire.</p>}
        <ul className="space-y-3">
          {aFaire.map((d) => <CarteDevoir key={d.id} devoir={d} charger={apiVieClasseParent.piece} />)}
        </ul>
      </section>

      {passes.length > 0 && (
        <section>
          <button type="button" onClick={() => setPassesVisibles(!passesVisibles)} className="mb-3 text-sm font-bold" style={{ color: 'var(--brand-accent)' }}>
            {passesVisibles ? 'Masquer les devoirs passés' : `Voir les devoirs passés (${passes.length})`}
          </button>
          {passesVisibles && (
            <ul className="space-y-3">
              {passes.map((d) => <CarteDevoir key={d.id} devoir={d} charger={apiVieClasseParent.piece} />)}
            </ul>
          )}
        </section>
      )}
    </div>
  )
}
