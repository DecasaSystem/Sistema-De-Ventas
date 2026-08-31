/**
 * Los iconos que una empresa puede ponerle a sus módulos y herramientas.
 *
 * Es una lista escogida, no todo el paquete: heroicons trae más de trescientos
 * y elegir entre trescientos dibujos parecidos no es elegir, es rendirse. Están
 * los que sirven para nombrar trabajo —guardar, mover, cobrar, fabricar,
 * medir— y agrupados como los buscaría alguien que está decidiendo.
 *
 * Se guarda el NOMBRE del icono, no el dibujo. Si mañana llega uno que no está
 * en esta lista, la pantalla usa el que trae escrito por defecto en vez de
 * quedarse en blanco.
 */
import {
  HomeIcon, PlusIcon, ClipboardDocumentListIcon, ClipboardDocumentCheckIcon,
  ClipboardDocumentIcon, DocumentTextIcon, DocumentCurrencyDollarIcon, DocumentDuplicateIcon,
  UserGroupIcon, UsersIcon, UserCircleIcon, UserPlusIcon, IdentificationIcon,
  ArchiveBoxIcon, ArchiveBoxArrowDownIcon, CubeIcon, CubeTransparentIcon, InboxStackIcon,
  ShoppingCartIcon, ShoppingBagIcon, BuildingStorefrontIcon, BuildingOffice2Icon, BuildingLibraryIcon,
  TruckIcon, ArrowPathIcon, ArrowsRightLeftIcon, ArrowUpTrayIcon, ArrowDownTrayIcon,
  WrenchScrewdriverIcon, WrenchIcon, CogIcon, Cog6ToothIcon, BeakerIcon, FireIcon, BoltIcon,
  ScissorsIcon, SwatchIcon, PaintBrushIcon, SparklesIcon, PuzzlePieceIcon, CpuChipIcon,
  ComputerDesktopIcon, DevicePhoneMobileIcon, ServerStackIcon, WifiIcon,
  BanknotesIcon, CurrencyDollarIcon, CreditCardIcon, CalculatorIcon, ReceiptPercentIcon,
  ScaleIcon, ChartBarIcon, ChartPieIcon, PresentationChartLineIcon, PresentationChartBarIcon,
  CalendarDaysIcon, CalendarIcon, ClockIcon, BellAlertIcon,
  ChatBubbleLeftRightIcon, ChatBubbleLeftEllipsisIcon, MegaphoneIcon, EnvelopeIcon, PhoneIcon,
  MapPinIcon, GlobeAmericasIcon, FlagIcon, BriefcaseIcon, KeyIcon, ShieldCheckIcon,
  TagIcon, TicketIcon, GiftIcon, StarIcon, HeartIcon, BookOpenIcon, PhotoIcon, CameraIcon,
  MagnifyingGlassIcon, AdjustmentsHorizontalIcon, Squares2X2Icon, ListBulletIcon, TableCellsIcon,
} from '@heroicons/vue/24/outline'

/** Nombre → dibujo. Es lo que usa la pantalla para pintar. */
export const ICONOS = {
  HomeIcon, PlusIcon, ClipboardDocumentListIcon, ClipboardDocumentCheckIcon,
  ClipboardDocumentIcon, DocumentTextIcon, DocumentCurrencyDollarIcon, DocumentDuplicateIcon,
  UserGroupIcon, UsersIcon, UserCircleIcon, UserPlusIcon, IdentificationIcon,
  ArchiveBoxIcon, ArchiveBoxArrowDownIcon, CubeIcon, CubeTransparentIcon, InboxStackIcon,
  ShoppingCartIcon, ShoppingBagIcon, BuildingStorefrontIcon, BuildingOffice2Icon, BuildingLibraryIcon,
  TruckIcon, ArrowPathIcon, ArrowsRightLeftIcon, ArrowUpTrayIcon, ArrowDownTrayIcon,
  WrenchScrewdriverIcon, WrenchIcon, CogIcon, Cog6ToothIcon, BeakerIcon, FireIcon, BoltIcon,
  ScissorsIcon, SwatchIcon, PaintBrushIcon, SparklesIcon, PuzzlePieceIcon, CpuChipIcon,
  ComputerDesktopIcon, DevicePhoneMobileIcon, ServerStackIcon, WifiIcon,
  BanknotesIcon, CurrencyDollarIcon, CreditCardIcon, CalculatorIcon, ReceiptPercentIcon,
  ScaleIcon, ChartBarIcon, ChartPieIcon, PresentationChartLineIcon, PresentationChartBarIcon,
  CalendarDaysIcon, CalendarIcon, ClockIcon, BellAlertIcon,
  ChatBubbleLeftRightIcon, ChatBubbleLeftEllipsisIcon, MegaphoneIcon, EnvelopeIcon, PhoneIcon,
  MapPinIcon, GlobeAmericasIcon, FlagIcon, BriefcaseIcon, KeyIcon, ShieldCheckIcon,
  TagIcon, TicketIcon, GiftIcon, StarIcon, HeartIcon, BookOpenIcon, PhotoIcon, CameraIcon,
  MagnifyingGlassIcon, AdjustmentsHorizontalIcon, Squares2X2Icon, ListBulletIcon, TableCellsIcon,
}

/** Cómo se ofrecen al elegir: por lo que sirven, no por orden alfabético. */
export const GRUPOS_ICONOS = [
  {
    nombre: 'Ventas y clientes',
    iconos: ['PlusIcon', 'ClipboardDocumentListIcon', 'ClipboardDocumentCheckIcon', 'DocumentTextIcon',
      'DocumentCurrencyDollarIcon', 'DocumentDuplicateIcon', 'UserGroupIcon', 'UsersIcon',
      'UserPlusIcon', 'IdentificationIcon', 'TagIcon', 'TicketIcon', 'GiftIcon'],
  },
  {
    nombre: 'Bodega y movimiento',
    iconos: ['ArchiveBoxIcon', 'ArchiveBoxArrowDownIcon', 'CubeIcon', 'CubeTransparentIcon',
      'InboxStackIcon', 'TruckIcon', 'ArrowPathIcon', 'ArrowsRightLeftIcon',
      'ArrowUpTrayIcon', 'ArrowDownTrayIcon', 'ShoppingCartIcon', 'ShoppingBagIcon'],
  },
  {
    nombre: 'Taller y producción',
    iconos: ['WrenchScrewdriverIcon', 'WrenchIcon', 'CogIcon', 'Cog6ToothIcon', 'BeakerIcon',
      'FireIcon', 'BoltIcon', 'ScissorsIcon', 'SwatchIcon', 'PaintBrushIcon',
      'SparklesIcon', 'PuzzlePieceIcon'],
  },
  {
    nombre: 'Tecnología',
    iconos: ['CpuChipIcon', 'ComputerDesktopIcon', 'DevicePhoneMobileIcon', 'ServerStackIcon', 'WifiIcon'],
  },
  {
    nombre: 'Dinero',
    iconos: ['BanknotesIcon', 'CurrencyDollarIcon', 'CreditCardIcon', 'CalculatorIcon',
      'ReceiptPercentIcon', 'ScaleIcon'],
  },
  {
    nombre: 'Números y tiempo',
    iconos: ['ChartBarIcon', 'ChartPieIcon', 'PresentationChartLineIcon', 'PresentationChartBarIcon',
      'TableCellsIcon', 'CalendarDaysIcon', 'CalendarIcon', 'ClockIcon', 'BellAlertIcon'],
  },
  {
    nombre: 'Comunicación',
    iconos: ['ChatBubbleLeftRightIcon', 'ChatBubbleLeftEllipsisIcon', 'MegaphoneIcon',
      'EnvelopeIcon', 'PhoneIcon', 'MapPinIcon', 'GlobeAmericasIcon'],
  },
  {
    nombre: 'Lugares y otros',
    iconos: ['HomeIcon', 'BuildingStorefrontIcon', 'BuildingOffice2Icon', 'BuildingLibraryIcon',
      'BriefcaseIcon', 'KeyIcon', 'ShieldCheckIcon', 'FlagIcon', 'StarIcon', 'HeartIcon',
      'BookOpenIcon', 'PhotoIcon', 'CameraIcon', 'ClipboardDocumentIcon', 'UserCircleIcon',
      'MagnifyingGlassIcon', 'AdjustmentsHorizontalIcon', 'Squares2X2Icon', 'ListBulletIcon'],
  },
]

/** El dibujo de un nombre, o nada si ese nombre no está en la lista. */
export function iconoPorNombre(nombre) {
  return ICONOS[nombre] ?? null
}
