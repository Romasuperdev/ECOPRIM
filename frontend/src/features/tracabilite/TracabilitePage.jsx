import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Info, Lock, ShieldAlert } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import { fetchTracabilite, fetchTracabiliteUtilisateur } from './tracabiliteApi'

const VIDE = { utilisateur: '', action: '', du: '', au: '', q: '' }

/**
 * Traçabilité des utilisateurs — ECONOMAT.T_TRACABILITE, en lecture seule.
 *
 * La structure de cette table n'est pas connue de NEXORA : le serveur découvre ses colonnes
 * à l'exécution et renvoie la correspondance qu'il a établie. L'écran s'y adapte — il
 * n'affiche que les colonnes reconnues, et expose la correspondance retenue pour qu'on
 * puisse vérifier qu'elle est juste.
 *
 * Deux emplois : la liste générale (/admin/tracabilite) et la traçabilité d'un compte
 * précis, atteinte depuis sa fiche (/admin/utilisateurs/:id/tracabilite).
 */
export default function TracabilitePage() {
  const { id } = useParams()
  const [filtres, setFiltres] = useState({ ...VIDE })
  const [page, setPage] = useState(1)
  const [detailColonnes, setDetailColonnes] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: ['tracabilite', id ?? 'tous', filtres, page],
    queryFn: () => (id
      ? fetchTracabiliteUtilisateur(id, { ...filtres, page })
      : fetchTracabilite({ ...filtres, page })),
    retry: false,
  })

  const colonnes = data?.colonnes ?? {}
  const lignes = data?.data ?? []
  const champ = (k, v) => { setPage(1); setFiltres((f) => ({ ...f, [k]: v })) }

  // Ordre de lecture : quand, qui, quoi, sur quoi, détail, où.
  const ROLES = [
    ['date', 'Quand'],
    ['utilisateur', 'Utilisateur'],
    ['action', 'Action'],
    ['objet', 'Sur quoi'],
    ['detail', 'Détail'],
    ['poste', 'Poste'],
    ['etablissement', 'Établissement'],
    ['societe', 'Société'],
  ].filter(([role]) => colonnes[role])

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">
          {id ? `Traçabilité — ${data?.utilisateur?.nom ?? 'utilisateur'}` : 'Traçabilité'}
        </h1>
        <p className="mt-1 text-sm text-slate-500">
          Journal d’activité tenu par ECONOMAT (T_TRACABILITE), en lecture seule.
          {data?.portee && <> Portée : <strong>{data.portee}</strong>.</>}
        </p>
      </div>

      {/* Pourquoi il n'y a rien à voir : le distinguer d'un journal vide. */}
      {data?.message && (
        <div
          className={`mb-4 flex items-start gap-2 rounded-xl border px-4 py-3 text-sm ${
            data.strategie === 'impossible'
              ? 'border-amber-200 bg-amber-50 text-amber-800'
              : 'border-slate-200 bg-white text-slate-600'
          }`}
        >
          {data.strategie === 'impossible' ? <ShieldAlert size={16} className="mt-0.5 shrink-0" /> : <Info size={16} className="mt-0.5 shrink-0" />}
          <span>{data.message}</span>
        </div>
      )}

      {/* Le cloisonnement indirect mérite d'être annoncé : il est moins fiable. */}
      {data?.strategie === 'utilisateur' && (
        <div className="mb-4 flex items-start gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
          <Lock size={16} className="mt-0.5 shrink-0" />
          <span>
            Cette table ne porte pas de société : les lignes affichées sont celles des comptes
            affectés dans <strong>{data.portee}</strong>. Une activité enregistrée sous un
            identifiant inconnu de la console n’y apparaît pas.
          </span>
        </div>
      )}

      {id && data?.utilisateur?.identifiants_cherches?.length > 0 && (
        <p className="mb-4 text-xs text-slate-400">
          Recherché sous : {data.utilisateur.identifiants_cherches.join(', ')} — ECONOMAT
          n’enregistre pas partout le même identifiant.
        </p>
      )}

      {/* Filtres : seuls ceux que la table permet réellement. */}
      {ROLES.length > 0 && (
        <div className="mb-4 flex flex-wrap items-end gap-3">
          {!id && colonnes.utilisateur && (
            <div className="min-w-[170px]">
              <Input label="Utilisateur" placeholder="login ou matricule" value={filtres.utilisateur}
                     onChange={(e) => champ('utilisateur', e.target.value)} />
            </div>
          )}
          {colonnes.action && (
            <div className="min-w-[150px]">
              <Input label="Action" placeholder="Connexion…" value={filtres.action}
                     onChange={(e) => champ('action', e.target.value)} />
            </div>
          )}
          {colonnes.date && (
            <>
              <div className="min-w-[150px]">
                <Input label="Du" type="date" value={filtres.du} onChange={(e) => champ('du', e.target.value)} />
              </div>
              <div className="min-w-[150px]">
                <Input label="Au" type="date" value={filtres.au} onChange={(e) => champ('au', e.target.value)} />
              </div>
            </>
          )}
          {(colonnes.objet || colonnes.detail) && (
            <div className="min-w-[190px] flex-1">
              <Input label="Rechercher" placeholder="table, écran, détail…" value={filtres.q}
                     onChange={(e) => champ('q', e.target.value)} />
            </div>
          )}
          <Button variant="outline" onClick={() => { setFiltres({ ...VIDE }); setPage(1) }}>
            Réinitialiser
          </Button>
        </div>
      )}

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              {ROLES.map(([role, libelle]) => (
                <th key={role} className="px-4 py-3 font-medium whitespace-nowrap">{libelle}</th>
              ))}
              {ROLES.length === 0 && <th className="px-4 py-3 font-medium">Traçabilité</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={Math.max(ROLES.length, 1)} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {!isLoading && lignes.length === 0 && (
              <tr>
                <td colSpan={Math.max(ROLES.length, 1)} className="px-4 py-6 text-center text-slate-400">
                  Aucune trace pour ces critères.
                </td>
              </tr>
            )}
            {lignes.map((l, i) => (
              <tr key={`${l.id ?? i}-${i}`} className="hover:bg-slate-50">
                {ROLES.map(([role]) => (
                  <td key={role} className={`px-4 py-3 ${role === 'utilisateur' ? 'font-medium text-slate-800' : 'text-slate-600'}`}>
                    {l[role] === null || l[role] === '' ? '—' : String(l[role])}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {data && data.last_page > 1 && (
        <div className="mt-4 flex items-center justify-end gap-3">
          <span className="text-xs text-slate-400">
            Page {data.current_page} sur {data.last_page} · {data.total} trace{data.total > 1 ? 's' : ''}
          </span>
          <Button variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Précédent</Button>
          <Button variant="outline" disabled={page >= data.last_page} onClick={() => setPage((p) => p + 1)}>Suivant</Button>
        </div>
      )}

      {/* Vérifiable : la correspondance a été devinée, elle doit pouvoir être contrôlée. */}
      {data?.colonnes_reelles?.length > 0 && (
        <div className="mt-6 rounded-xl border border-slate-200 bg-white p-4 text-xs text-slate-500">
          <button
            type="button"
            className="font-medium text-slate-600 underline"
            onClick={() => setDetailColonnes((v) => !v)}
          >
            {detailColonnes ? 'Masquer' : 'Voir'} la correspondance des colonnes
          </button>
          {detailColonnes && (
            <div className="mt-3 space-y-2">
              <p>
                NEXORA ne connaît pas la structure de cette table : il lit ses colonnes à
                l’exécution et les rapproche de rôles métier. Si un rapprochement est faux,
                c’est ici qu’on le voit.
              </p>
              <ul className="space-y-1">
                {Object.entries(colonnes).map(([role, colonne]) => (
                  <li key={role}>
                    <span className="text-slate-400">{role}</span> → <code>{colonne}</code>
                  </li>
                ))}
              </ul>
              <p className="text-slate-400">
                Colonnes réelles : {data.colonnes_reelles.join(', ')}
              </p>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
