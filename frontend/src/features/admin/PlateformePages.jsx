import { useQuery } from '@tanstack/react-query'
import { AppWindow, CheckCircle2, CreditCard, Link2, MinusCircle, Settings2 } from 'lucide-react'
import MessageErreur from '../../components/ui/MessageErreur'
import {
  fetchAbonnements, fetchAffectationsPlateforme, fetchApplications, fetchParametresSysteme,
} from './plateformeApi'

/*
 * Les quatre écrans de la console plateforme.
 *
 * Réunis dans un fichier parce qu'ils partagent tout — même en-tête, même tableau, même
 * bandeau de lecture seule — et ne diffèrent que par leurs colonnes. Séparés, c'est ce
 * cadre commun qui aurait été recopié quatre fois, puis aurait divergé.
 *
 * TOUS EN LECTURE SEULE. Ces tables vivent dans la base partagée avec ECONOMAT et les
 * autres applications de la suite : ECOPRIM les consulte, il ne les écrit pas. L'écran le
 * dit, pour qu'on ne cherche pas un bouton qui n'existera pas.
 */

function EnTete({ icon: Icon, titre, sousTitre }) {
  return (
    <header className="mb-5">
      <h1 className="flex items-center gap-2.5 text-2xl font-bold text-heading">
        <Icon size={24} strokeWidth={2.5} style={{ color: 'var(--brand-accent)' }} />
        {titre}
      </h1>
      <p className="mt-1 max-w-3xl text-sm font-medium text-muted">{sousTitre}</p>
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
function Ecran({ requete, cle, icon, titre, sousTitre, enfants }) {
  const { data, isLoading, error } = useQuery({ queryKey: [cle], queryFn: requete, retry: false })

  return (
    <div>
      <EnTete icon={icon} titre={titre} sousTitre={sousTitre} />
      {isLoading && <p className="font-medium text-muted">Chargement…</p>}
      {!isLoading && !data && <MessageErreur erreur={error} permission="Console générale (Super Admin)" />}
      {data && (
        <>
          <BandeauPartage />
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

export function AffectationsPlateformePage() {
  return (
    <Ecran
      cle="plateforme-affectations" requete={fetchAffectationsPlateforme} icon={Link2}
      titre="Affectations société" sousTitre="Qui est rattaché à quelle société, au niveau de la plateforme. À distinguer des affectations par établissement, qui portent un rôle."
      enfants={(data) => (
        <Tableau
          colonnes={['Société', 'Utilisateur', 'Identifiant', 'Email', 'Rôle']}
          vide="Aucune affectation enregistrée."
          lignes={data.affectations.map((a) => (
            <tr key={a.id}>
              <td className="px-4 py-3 font-semibold text-heading">
                {a.societe_nom ?? a.societe_code}
                {a.societe_nom && <span className="ml-1.5 font-mono text-xs font-normal text-muted">{a.societe_code}</span>}
              </td>
              <td className="px-4 py-3 text-heading">{a.utilisateur ?? `#${a.utilisateur_id}`}</td>
              <td className="px-4 py-3 font-mono text-xs text-muted">{a.login ?? '—'}</td>
              <td className="px-4 py-3 text-muted">{a.email ?? '—'}</td>
              <td className="px-4 py-3">
                <span
                  className="rounded-full px-2 py-0.5 text-xs font-bold"
                  style={a.admin_societe
                    ? { background: 'var(--info-bg)', color: 'var(--info-text)' }
                    : { background: 'var(--surface-2)', color: 'var(--text)' }}
                >
                  {a.admin_societe ? 'Admin société' : 'Rattaché'}
                </span>
              </td>
            </tr>
          ))}
        />
      )}
    />
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
              Aucune formule ni abonnement n’est enregistré pour l’instant. Les tables existent
              et répondent : la facturation de la plateforme n’est simplement pas encore
              alimentée.
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
