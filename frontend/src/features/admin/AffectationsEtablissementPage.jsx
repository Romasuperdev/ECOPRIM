import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Clock, Link2, Search } from 'lucide-react'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import MessageErreur from '../../components/ui/MessageErreur'
import apiClient from '../../api/client'

const fetchAffectations = () => apiClient.get('affectations').then((r) => r.data)

/**
 * Qui travaille dans quel établissement.
 *
 * Ces affectations ne se consultaient que compte par compte, depuis la fiche d'un
 * utilisateur : répondre à « qui travaille ici ? » demandait d'ouvrir les comptes un à un.
 * C'est pourtant la première question qu'on se pose devant un établissement.
 *
 * LECTURE SEULE, et c'est délibéré. Affecter ou retirer se fait sur la fiche de
 * l'utilisateur, où les règles sont déjà appliquées — une seule société par compte, pas de
 * doublon, pas de rôle Admin Société conféré par un Admin Établissement. Un second endroit
 * pour écrire aurait voulu dire une seconde copie de ces règles, et elles auraient fini par
 * différer. Chaque ligne mène donc à la fiche, là où l'on agit.
 */
export default function AffectationsEtablissementPage() {
  const [recherche, setRecherche] = useState('')
  const [etablissement, setEtablissement] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: ['affectations-console'],
    queryFn: fetchAffectations,
    retry: false,
  })

  const etablissements = useMemo(() => {
    const vus = new Map()
    for (const a of data ?? []) {
      if (!vus.has(a.etablissement_code)) vus.set(a.etablissement_code, a.etablissement ?? a.etablissement_code)
    }
    return [...vus.entries()].sort((x, y) => x[1].localeCompare(y[1]))
  }, [data])

  const lignes = useMemo(() => {
    const q = recherche.trim().toLowerCase()
    return (data ?? []).filter((a) => {
      if (etablissement && a.etablissement_code !== etablissement) return false
      if (!q) return true
      return [a.utilisateur, a.login, a.email, a.role, a.etablissement]
        .some((v) => (v ?? '').toLowerCase().includes(q))
    })
  }, [data, recherche, etablissement])

  return (
    <div>
      <header className="mb-5">
        <h1 className="flex items-center gap-2.5 text-2xl font-bold text-heading">
          <Link2 size={24} strokeWidth={2.5} style={{ color: 'var(--brand-accent)' }} />
          Affectations par établissement
        </h1>
        <p className="mt-1 max-w-3xl text-sm font-medium text-muted">
          Qui travaille dans quel établissement, et à quel titre. Pour affecter ou retirer
          quelqu’un, ouvrez sa fiche : c’est là que les règles s’appliquent.
        </p>
      </header>

      {isLoading && <p className="font-medium text-muted">Chargement…</p>}
      {!isLoading && !data && <MessageErreur erreur={error} permission="Console administrative" />}

      {data && (
        <>
          <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Input
              icon={Search}
              placeholder="Nom, identifiant, email, rôle…"
              value={recherche}
              onChange={(e) => setRecherche(e.target.value)}
              aria-label="Rechercher une affectation"
            />
            <Select value={etablissement} onChange={(e) => setEtablissement(e.target.value)} aria-label="Filtrer par établissement">
              <option value="">Tous les établissements</option>
              {etablissements.map(([code, nom]) => (
                <option key={code} value={code}>{nom}</option>
              ))}
            </Select>
          </div>

          {lignes.length === 0 ? (
            <p className="rounded-xl border border-slate-200 bg-card px-4 py-10 text-center text-sm font-medium text-muted">
              {(data.length === 0)
                ? 'Aucune affectation enregistrée dans votre périmètre.'
                : 'Aucune affectation ne correspond à cette recherche.'}
            </p>
          ) : (
            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-card">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-muted">
                  <tr>
                    <th className="px-4 py-3 font-bold">Établissement</th>
                    <th className="px-4 py-3 font-bold">Utilisateur</th>
                    <th className="px-4 py-3 font-bold">Identifiant</th>
                    <th className="px-4 py-3 font-bold">Rôle</th>
                    <th className="px-4 py-3 font-bold">Période</th>
                    <th className="px-4 py-3 font-bold">État</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {lignes.map((a) => (
                    <tr key={a.id} className="hover:bg-slate-50">
                      <td className="px-4 py-3 font-semibold text-heading">
                        {a.etablissement ?? a.etablissement_code}
                      </td>
                      <td className="px-4 py-3">
                        {/* Chaque ligne mène à la fiche : c'est là qu'on agit. */}
                        <Link
                          to={`/admin/utilisateurs/${a.rh_user_id}`}
                          className="font-semibold underline"
                          style={{ color: 'var(--brand-accent)' }}
                        >
                          {a.utilisateur ?? `Compte #${a.rh_user_id}`}
                        </Link>
                        {a.email && <div className="text-xs text-muted">{a.email}</div>}
                      </td>
                      <td className="px-4 py-3 font-mono text-xs text-muted">{a.login ?? '—'}</td>
                      <td className="px-4 py-3 text-muted">{a.role ?? '—'}</td>
                      <td className="px-4 py-3 text-xs text-muted">
                        {a.date_debut || a.date_fin
                          ? `${a.date_debut ?? '…'} → ${a.date_fin ?? '…'}`
                          : 'Sans terme'}
                      </td>
                      <td className="px-4 py-3">
                        {!a.actif ? (
                          <span className="rounded-full px-2 py-0.5 text-xs font-bold"
                                style={{ background: 'var(--surface-2)', color: 'var(--text)' }}>
                            Désactivée
                          </span>
                        ) : a.echue ? (
                          // Une affectation échue reste en base — c'est un historique — mais
                          // elle n'ouvre plus rien. Le dire évite de la croire agissante.
                          <span className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-bold"
                                style={{ background: 'var(--warning-bg)', color: 'var(--warning-text)' }}>
                            <Clock size={12} /> Échue
                          </span>
                        ) : (
                          <span className="rounded-full px-2 py-0.5 text-xs font-bold"
                                style={{ background: 'var(--success-bg)', color: 'var(--success-text)' }}>
                            Active
                          </span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <p className="mt-3 text-xs font-semibold text-muted">
            {lignes.length} affectation{lignes.length > 1 ? 's' : ''} affichée
            {lignes.length > 1 ? 's' : ''}{lignes.length !== data.length && ` sur ${data.length}`}.
          </p>
        </>
      )}
    </div>
  )
}
