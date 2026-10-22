import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AppWindow, CheckCircle2, CreditCard, Link2, MinusCircle, Search, Settings2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import SelecteurMultiple from '../../components/ui/SelecteurMultiple'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import MessageErreur from '../../components/ui/MessageErreur'
import {
  definirAdminSociete, detacherCompte, fetchAbonnements, fetchAffectationsPlateforme, fetchApplications,
  fetchParametresSysteme, fetchReferentielsRattachement, rattacherCompte,
} from './plateformeApi'

/*
 * Les quatre écrans de la console plateforme.
 *
 * Réunis dans un fichier parce qu'ils partagent tout — même en-tête, même tableau, même
 * bandeau de lecture seule — et ne diffèrent que par leurs colonnes. Séparés, c'est ce
 * cadre commun qui aurait été recopié quatre fois, puis aurait divergé.
 *
 * EN LECTURE SEULE, sauf un. Ces tables vivent dans la base partagée avec ECONOMAT et
 * les autres applications de la suite : ECOPRIM les consulte, il ne les écrit pas. L'écran
 * le dit, pour qu'on ne cherche pas un bouton qui n'existera pas. L'exception est
 * Société - Utilisateurs : rattacher un compte à une société est la condition n° 2 de la
 * connexion, et l'administrateur doit pouvoir le faire d'ici.
 */

function EnTete({ icon: Icon, titre, sousTitre, action }) {
  return (
    <header className="mb-5 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 className="flex items-center gap-2.5 text-2xl font-bold text-heading">
          <Icon size={24} strokeWidth={2.5} style={{ color: 'var(--brand-accent)' }} />
          {titre}
        </h1>
        <p className="mt-1 max-w-3xl text-sm font-medium text-muted">{sousTitre}</p>
      </div>
      {action}
    </header>
  )
}

/** Dire d'où viennent les données et pourquoi rien ne s'y modifie. */
function BandeauPartage() {
  return (
    <p className="mb-4 rounded-xl border border-slate-200 bg-card px-4 py-3 text-sm font-medium text-muted">
      Données de la plateforme, communes à toute la suite NEXORA. Elles sont affichées en
      <strong className="text-heading"> consultation seule</strong> : leur source est partagée
      avec ECONOMAT et les autres applications, et c'est depuis la console de la plateforme
      qu'elles se modifient.
    </p>
  )
}

function Tableau({ colonnes, lignes, vide }) {
  if (lignes.length === 0) {
    return (
      <p className="rounded-xl border border-slate-200 bg-card px-4 py-10 text-center text-sm font-medium text-muted">
        {vide}
      </p>
    )
  }

  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-card">
      <table className="w-full text-left text-sm">
        <thead className="border-b border-slate-200 bg-slate-50 text-muted">
          <tr>
            {colonnes.map((c) => <th key={c} className="px-4 py-3 font-bold">{c}</th>)}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">{lignes}</tbody>
      </table>
    </div>
  )
}

/** Enveloppe commune : chargement, refus d'accès, puis le contenu. */
function Ecran({ requete, cle, icon, titre, sousTitre, enfants, action, modifiable = false }) {
  const { data, isLoading, error } = useQuery({ queryKey: [cle], queryFn: requete, retry: false })

  return (
    <div>
      <EnTete icon={icon} titre={titre} sousTitre={sousTitre} action={data && action} />
      {isLoading && <p className="font-medium text-muted">Chargement…</p>}
      {!isLoading && !data && <MessageErreur erreur={error} permission="Console générale (Super Admin)" />}
      {data && (
        <>
          {!modifiable && <BandeauPartage />}
          {data.disponible === false ? (
            <p className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-6 text-center text-sm font-medium text-amber-800">
              Cette table n’existe pas sur cette installation : il n’y a rien à afficher, et
              ce n’est pas une panne.
            </p>
          ) : enfants(data)}
        </>
      )}
    </div>
  )
}

export function ApplicationsPage() {
  return (
    <Ecran
      cle="plateforme-applications" requete={fetchApplications} icon={AppWindow}
      titre="Applications" sousTitre="Les logiciels de la suite NEXORA et le nombre de comptes qui y travaillent."
      enfants={(data) => (
        <>
          <Tableau
            colonnes={['Code', 'Application', 'Comptes']}
            vide="Aucune application au catalogue."
            lignes={data.applications.map((a) => (
              <tr key={a.code}>
                <td className="px-4 py-3 font-mono text-xs font-semibold text-muted">{a.code}</td>
                <td className="px-4 py-3 font-semibold text-heading">{a.libelle}</td>
                <td className="px-4 py-3 text-muted">{a.comptes}</td>
              </tr>
            ))}
          />
          {data.codes_hors_catalogue?.length > 0 && (
            <p className="mt-3 text-sm font-medium" style={{ color: 'var(--warning-text)' }}>
              Des comptes portent un code d’application absent du catalogue :{' '}
              <strong>{data.codes_hors_catalogue.join(', ')}</strong>. Les faire disparaître du
              total les aurait rendus introuvables.
            </p>
          )}
        </>
      )}
    />
  )
}

const RATTACHEMENT_VIDE = { utilisateur_id: '', societes: [], admin_societe: false }

/**
 * Société - Utilisateurs : qui est rattaché à quelle société, et le rattacher ici.
 *
 * Un compte sans société ne peut pas se connecter à l'application (« Ce compte n'est
 * rattaché à aucune société ») : c'est l'écran où l'administrateur règle ce cas.
 */
export function AffectationsPlateformePage() {
  const qc = useQueryClient()
  const [form, setForm] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [recherche, setRecherche] = useState('')

  const { data: referentiels } = useQuery({
    queryKey: ['plateforme-rattachement-referentiels'],
    queryFn: fetchReferentielsRattachement,
    enabled: !!form,
  })

  const invalider = () => qc.invalidateQueries({ queryKey: ['plateforme-affectations'] })

  const rattacher = useMutation({
    mutationFn: rattacherCompte,
    onSuccess: () => {
      invalider()
      setForm(null)
      setErreurs({})
    },
    onError: (e) => setErreurs(e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Erreur'] }),
  })
  const basculerAdmin = useMutation({
    mutationFn: ({ id, admin }) => definirAdminSociete(id, admin),
    onSuccess: invalider,
  })
  const detacher = useMutation({ mutationFn: detacherCompte, onSuccess: invalider })

  const ouvrir = (utilisateurId = '') => {
    setErreurs({})
    setForm({ ...RATTACHEMENT_VIDE, utilisateur_id: utilisateurId })
  }

  const confirmerDetacher = (a) => {
    const qui = a.utilisateur ?? a.login ?? `#${a.utilisateur_id}`
    if (window.confirm(`Détacher ${qui} de ${a.societe_nom ?? a.societe_code} ? Le compte n’y aura plus accès.`)) {
      detacher.mutate(a.id)
    }
  }

  return (
    <>
      <Ecran
        cle="plateforme-affectations" requete={fetchAffectationsPlateforme} icon={Link2} modifiable
        titre="Société - Utilisateurs"
        sousTitre="Qui est rattaché à quelle société. Un compte sans société ne peut pas se connecter à l’application. À distinguer des affectations par établissement, qui portent un rôle."
        action={<Button onClick={() => ouvrir()}>+ Rattacher un compte</Button>}
        enfants={(data) => (
          <ListeRattachements
            affectations={data.affectations}
            recherche={recherche}
            setRecherche={setRecherche}
            onAjouter={ouvrir}
            onBasculerAdmin={(a) => basculerAdmin.mutate({ id: a.id, admin: !a.admin_societe })}
            onDetacher={confirmerDetacher}
            occupe={basculerAdmin.isPending || detacher.isPending}
          />
        )}
      />

      {form && (
        <ModaleFormulaire
          titre="Rattacher un compte à des sociétés"
          sousTitre="Un rattachement déjà présent n’est pas dupliqué : seul son statut d’administrateur est mis à jour."
          onFermer={() => setForm(null)}
          onValider={() => rattacher.mutate({ ...form, utilisateur_id: Number(form.utilisateur_id) })}
          enCours={rattacher.isPending}
          valideDesactive={!form.utilisateur_id || form.societes.length === 0}
          libelleValider="Rattacher"
        >
          <Select
            id="rattachement-utilisateur" label="Compte *"
            value={form.utilisateur_id}
            onChange={(e) => setForm((f) => ({ ...f, utilisateur_id: e.target.value }))}
            error={erreurs.utilisateur_id?.[0]}
          >
            <option value="">— Choisir un compte —</option>
            {(referentiels?.utilisateurs ?? []).map((u) => (
              <option key={u.id} value={u.id}>{u.nom ? `${u.login} — ${u.nom}` : u.login}</option>
            ))}
          </Select>
          <SelecteurMultiple
            id="rattachement-societes" label="Sociétés *"
            options={(referentiels?.societes ?? []).map((s) => ({ valeur: s.code, libelle: `${s.nom} (${s.code})` }))}
            selection={form.societes}
            onChange={(societes) => setForm((f) => ({ ...f, societes }))}
            placeholder="— Choisir une ou plusieurs sociétés —"
            error={erreurs.societes?.[0]}
          />
          <label className="flex items-center gap-2 text-sm font-medium text-heading">
            <input
              type="checkbox" checked={form.admin_societe}
              onChange={(e) => setForm((f) => ({ ...f, admin_societe: e.target.checked }))}
            />
            Administrateur de ces sociétés (accès à leur console)
          </label>
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
        </ModaleFormulaire>
      )}
    </>
  )
}

function ListeRattachements({ affectations, recherche, setRecherche, onAjouter, onBasculerAdmin, onDetacher, occupe }) {
  const filtrees = useMemo(() => {
    const q = recherche.trim().toLowerCase()
    if (!q) return affectations
    return affectations.filter((a) =>
      [a.societe_nom, a.societe_code, a.utilisateur, a.login, a.email]
        .some((v) => v && String(v).toLowerCase().includes(q))
    )
  }, [affectations, recherche])

  return (
    <>
      <div className="mb-4 max-w-sm">
        <Input
          id="rattachement-recherche" icon={Search} placeholder="Rechercher une société, un compte…"
          value={recherche} onChange={(e) => setRecherche(e.target.value)}
        />
      </div>
      <Tableau
        colonnes={['Société', 'Utilisateur', 'Identifiant', 'Email', 'Rôle', '']}
        vide={recherche ? 'Aucun rattachement ne correspond à la recherche.' : 'Aucun compte rattaché pour l’instant.'}
        lignes={filtrees.map((a) => (
          <tr key={a.id}>
            <td className="px-4 py-3 font-semibold text-heading">
              {a.societe_nom ?? a.societe_code}
              {a.societe_nom && <span className="ml-1.5 font-mono text-xs font-normal text-muted">{a.societe_code}</span>}
            </td>
            <td className="px-4 py-3 text-heading">{a.utilisateur ?? `#${a.utilisateur_id}`}</td>
            <td className="px-4 py-3 font-mono text-xs text-muted">{a.login ?? '—'}</td>
            <td className="px-4 py-3 text-muted">{a.email ?? '—'}</td>
            <td className="px-4 py-3">
              {/* Un clic bascule le statut : c'est la seule chose qui se modifie sur un rattachement. */}
              <button
                type="button" disabled={occupe} onClick={() => onBasculerAdmin(a)}
                title={a.admin_societe ? 'Retirer le statut d’administrateur' : 'Rendre administrateur de la société'}
                className="rounded-full px-2.5 py-0.5 text-xs font-bold hover:opacity-80"
                style={a.admin_societe
                  ? { background: 'var(--info-bg)', color: 'var(--info-text)' }
                  : { background: 'var(--surface-2)', color: 'var(--text)' }}
              >
                {a.admin_societe ? 'Admin société' : 'Rattaché'}
              </button>
            </td>
            <td className="whitespace-nowrap px-4 py-3 text-right">
              <button
                type="button" onClick={() => onAjouter(String(a.utilisateur_id))}
                className="mr-3 text-xs font-bold hover:underline" style={{ color: 'var(--brand-accent)' }}
              >
                + Société
              </button>
              <button
                type="button" disabled={occupe} onClick={() => onDetacher(a)}
                className="text-xs font-bold hover:underline" style={{ color: 'var(--danger-text)' }}
              >
                Détacher
              </button>
            </td>
          </tr>
        ))}
      />
    </>
  )
}

export function AbonnementsPage() {
  return (
    <Ecran
      cle="plateforme-abonnements" requete={fetchAbonnements} icon={CreditCard}
      titre="Abonnements et formules" sousTitre="Les formules de la plateforme et les abonnements souscrits."
      enfants={(data) => (
        <>
          {/* Une table vide est une réponse, pas une panne — et le dire évite qu'on cherche
              une erreur là où il n'y a simplement rien encore. */}
          {!data.alimentee && (
            <p className="mb-4 rounded-xl border border-slate-200 bg-card px-4 py-6 text-center text-sm font-medium text-muted">
              Aucune formule ni abonnement n’est enregistré pour l’instant : la facturation de
              la plateforme n’est simplement pas encore en service.
            </p>
          )}

          <h2 className="mb-2 mt-5 text-base font-bold text-heading">Formules</h2>
          <Tableau
            colonnes={['Formule', 'Prix', 'Période', 'Élèves max.', 'Utilisateurs max.']}
            vide="Aucune formule définie."
            lignes={data.formules.map((f) => (
              <tr key={f.id}>
                <td className="px-4 py-3 font-semibold text-heading">{f.nom}</td>
                <td className="px-4 py-3 text-muted">{f.prix ?? '—'}</td>
                <td className="px-4 py-3 text-muted">{f.periode ?? '—'}</td>
                <td className="px-4 py-3 text-muted">{f.max_eleves ?? '—'}</td>
                <td className="px-4 py-3 text-muted">{f.max_utilisateurs ?? '—'}</td>
              </tr>
            ))}
          />

          <h2 className="mb-2 mt-6 text-base font-bold text-heading">Abonnements</h2>
          <Tableau
            colonnes={['Établissement', 'Formule', 'Début', 'Fin', 'Statut', 'Montant']}
            vide="Aucun abonnement souscrit."
            lignes={data.abonnements.map((a) => (
              <tr key={a.id}>
                <td className="px-4 py-3 font-semibold text-heading">{a.etablissement_id}</td>
                <td className="px-4 py-3 text-muted">{a.formule_id}</td>
                <td className="px-4 py-3 text-muted">{a.debut ?? '—'}</td>
                <td className="px-4 py-3 text-muted">{a.fin ?? '—'}</td>
                <td className="px-4 py-3 text-muted">{a.statut ?? '—'}</td>
                <td className="px-4 py-3 text-muted">{a.montant ?? '—'}</td>
              </tr>
            ))}
          />
        </>
      )}
    />
  )
}

export function ParametresSystemePage() {
  return (
    <Ecran
      cle="plateforme-parametres" requete={fetchParametresSysteme} icon={Settings2}
      titre="Paramètres système" sousTitre="Les réglages globaux de la suite, communs à toutes les applications."
      enfants={(data) => (
        <>
          <Tableau
            colonnes={['Clé', 'À quoi il sert', 'Valeur', 'Modifié le']}
            vide="Aucun paramètre système."
            lignes={data.parametres.map((p) => (
              <tr key={p.cle}>
                <td className="px-4 py-3 font-mono text-xs font-semibold text-heading">{p.cle}</td>
                <td className="px-4 py-3 text-muted">{p.description ?? '—'}</td>
                <td className="px-4 py-3">
                  {p.renseigne ? (
                    <span className="inline-flex items-center gap-1.5 text-sm font-semibold" style={{ color: 'var(--success-text)' }}>
                      <CheckCircle2 size={15} /> Renseignée
                    </span>
                  ) : (
                    <span className="inline-flex items-center gap-1.5 text-sm font-medium text-muted">
                      <MinusCircle size={15} /> Non renseignée
                    </span>
                  )}
                </td>
                <td className="px-4 py-3 text-muted">{p.modifie_le?.slice(0, 10) ?? '—'}</td>
              </tr>
            ))}
          />
          <p className="mt-3 text-xs font-semibold text-muted">
            Les valeurs ne sont pas affichées : certaines sont des secrets — clés d’API,
            jetons. Une console n’a pas à diffuser ce qu’elle protège ; on y lit seulement si
            le réglage est posé.
          </p>
        </>
      )}
    />
  )
}
