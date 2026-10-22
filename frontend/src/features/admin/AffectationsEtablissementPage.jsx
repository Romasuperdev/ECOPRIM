import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Clock, Link2, Search } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import MessageErreur from '../../components/ui/MessageErreur'
import apiClient from '../../api/client'
import SelecteurEleves from './SelecteurEleves'
import { createAffectation, fetchAllEtablissements, fetchRoles, terminerAffectation } from './adminApi'

const fetchAffectations = () => apiClient.get('affectations').then((r) => r.data)
// Comptes du périmètre de l'appelant — l'API les cloisonne déjà.
const fetchComptes = () => apiClient.get('utilisateurs', { params: { per_page: 200 } }).then((r) => r.data.data)

const AFFECTATION_VIDE = { rh_user_id: '', etablissement_code: '', role_id: '', eleves: [] }

/**
 * Utilisateur - Établissements : qui travaille dans quel établissement, et à quel titre.
 *
 * Ces affectations ne se consultaient que compte par compte, depuis la fiche d'un
 * utilisateur : répondre à « qui travaille ici ? » demandait d'ouvrir les comptes un à un.
 * C'est pourtant la première question qu'on se pose devant un établissement.
 *
 * On peut désormais affecter et retirer d'ici, comme dans la console parcauto. Les règles
 * ne sont pas recopiées pour autant : elles vivent dans l'API (AffectationController) — une
 * seule société par compte, pas de doublon, pas de rôle Admin Société conféré par un Admin
 * Établissement — et s'appliquent de la même façon qu'on passe par ici ou par la fiche.
 */
export default function AffectationsEtablissementPage() {
  const qc = useQueryClient()
  const [recherche, setRecherche] = useState('')
  const [etablissement, setEtablissement] = useState('')
  const [form, setForm] = useState(null)
  const [erreur, setErreur] = useState(null)

  const { data, isLoading, error } = useQuery({
    queryKey: ['affectations-console'],
    queryFn: fetchAffectations,
    retry: false,
  })

  // Listes du formulaire, chargées seulement à son ouverture.
  const { data: comptes } = useQuery({ queryKey: ['comptes-affectation'], queryFn: fetchComptes, enabled: !!form })
  const { data: etabs } = useQuery({ queryKey: ['etabs-affectation', 'tous'], queryFn: () => fetchAllEtablissements(), enabled: !!form })
  const { data: roles } = useQuery({ queryKey: ['roles'], queryFn: fetchRoles, enabled: !!form })

  const estRoleParent = roles?.find((r) => String(r.id) === String(form?.role_id))?.code === 'parent'

  const invalider = () => {
    qc.invalidateQueries({ queryKey: ['affectations-console'] })
    qc.invalidateQueries({ queryKey: ['utilisateurs'] })
  }

  const affecter = useMutation({
    mutationFn: (v) => createAffectation({
      rh_user_id: Number(v.rh_user_id), etablissement_code: v.etablissement_code, role_id: Number(v.role_id),
      eleves: estRoleParent ? v.eleves.map((e) => e.matricule) : undefined,
    }),
    onSuccess: () => { invalider(); setForm(null); setErreur(null) },
    onError: (e) => {
      const err = e?.response?.data?.errors
      setErreur(err ? Object.values(err).flat()[0] : (e?.response?.data?.message ?? 'Erreur'))
    },
  })

  const retirer = useMutation({ mutationFn: terminerAffectation, onSuccess: invalider })

  const confirmerRetrait = (a) => {
    const qui = a.utilisateur ?? a.login ?? `le compte #${a.rh_user_id}`
    const ou = a.etablissement ?? a.etablissement_code
    if (window.confirm(`Retirer ${qui} de ${ou} (${a.role ?? 'rôle'}) ?`)) retirer.mutate(a.id)
  }

  const champ = (k, v) => setForm((f) => ({ ...f, [k]: v }))

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
      <header className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2.5 text-2xl font-bold text-heading">
            <Link2 size={24} strokeWidth={2.5} style={{ color: 'var(--brand-accent)' }} />
            Utilisateur - Établissements
          </h1>
          <p className="mt-1 max-w-3xl text-sm font-medium text-muted">
            Qui travaille dans quel établissement, et à quel titre. Un compte sans affectation
            ne peut ouvrir aucun établissement.
          </p>
        </div>
        <Button onClick={() => { setErreur(null); setForm({ ...AFFECTATION_VIDE }) }}>+ Affecter un compte</Button>
      </header>

      {form && (
        <ModaleFormulaire
          titre="Affecter un compte à un établissement"
          sousTitre="Un compte n’appartient qu’à une seule société : ses établissements doivent tous en faire partie."
          onFermer={() => setForm(null)}
          onValider={() => affecter.mutate(form)}
          enCours={affecter.isPending}
          valideDesactive={!form.rh_user_id || !form.etablissement_code || !form.role_id}
          libelleValider="Affecter"
        >
          <Select id="affectation-compte" label="Compte *" value={form.rh_user_id} onChange={(e) => champ('rh_user_id', e.target.value)}>
            <option value="">— Choisir un compte —</option>
            {(comptes ?? []).filter((u) => u.actif).map((u) => (
              <option key={u.id} value={u.id}>{u.name === u.login ? u.login : `${u.name} (${u.login})`}</option>
            ))}
          </Select>
          <Select id="affectation-etab" label="Établissement *" value={form.etablissement_code} onChange={(e) => champ('etablissement_code', e.target.value)}>
            <option value="">— Choisir un établissement —</option>
            {(etabs ?? []).map((e) => <option key={e.code} value={e.code}>{e.intitule} ({e.code})</option>)}
          </Select>
          <Select id="affectation-role" label="Rôle *" value={form.role_id} onChange={(e) => champ('role_id', e.target.value)}>
            <option value="">— Choisir un rôle —</option>
            {(roles ?? []).map((r) => <option key={r.id} value={r.id}>{r.nom}</option>)}
          </Select>
          {estRoleParent && <SelecteurEleves selection={form.eleves} onChange={(eleves) => champ('eleves', eleves)} />}
          {erreur && <p className="text-sm text-red-600">{erreur}</p>}
        </ModaleFormulaire>
      )}

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
                    <th className="px-4 py-3" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {lignes.map((a) => (
                    <tr key={a.id} className="hover:bg-slate-50">
                      <td className="px-4 py-3 font-semibold text-heading">
                        {a.etablissement ?? a.etablissement_code}
                      </td>
                      <td className="px-4 py-3">
                        {/* La fiche garde l'historique complet du compte. */}
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
                      <td className="px-4 py-3 text-right">
                        <button
                          type="button" disabled={retirer.isPending} onClick={() => confirmerRetrait(a)}
                          className="text-xs font-bold hover:underline" style={{ color: 'var(--danger-text)' }}
                        >
                          Retirer
                        </button>
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
